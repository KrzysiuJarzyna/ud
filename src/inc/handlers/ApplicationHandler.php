<?PHP

require_once(__DIR__ . '/AbstractHandler.php');

use app\Application;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpForbiddenException;

/**
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
class ApplicationHandler extends AbstractHandler {
    public function start(Request $request, Response $response): Response {
        return AbstractHandler::renderHtml($request, $response, 'start', [
            'latestTermUpdate' => LATEST_TERMS_UPDATE
        ]);
    }

    /**
     * @SuppressWarnings(PHPMD.MissingImport)
     * @SuppressWarnings(PHPMD.Superglobals)
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(ShortVariable)
     * @TODO
     */
    public function newApplication(Request $request, Response $response): Response {
        $params = $request->getQueryParams();
        if (isset($params['cleanup'])) {
            unset($_SESSION['newAppId']);
            return $this->redirect('/nowe-zgloszenie.html');
        }

        $user = $request->getAttribute('user');

        if (isset($params['TermsConfirmation'])) {
            $user->confirmTerms();
            \user\save($user);
        }

        if (!$user->checkTermsConfirmation()) {
            return $this->redirect('/start.html');
        }

        if (isset($params['edit'])) {
            $application = \app\get($params['edit']);
            if (!$application->isEditable()) {
                throw new Exception("Nie mogę pozwolić na edycję zgłoszenia w statusie " . $application->getStatus()->name, 403);
            }

            if (!$application->isAppOwner($user)) {
                throw new Exception("Próba edycji cudzego zgłoszenia. Nieładnie!");
            }

            $_SESSION['newAppId'] = $application->id;
            $edit = true;
        } elseif (isset($_SESSION['newAppId'])) { // edit mode
            try {
                $application = \app\get($_SESSION['newAppId']);
                $edit = isset($application->carImage)
                     || isset($application->contextImage)
                     || isset($application->thirdImage);
                $application->updateUserData($user);
                if (!$edit) {
                    unset($application);
                } elseif (!$application->isEditable()) {
                    unset($application);
                }
            } catch (Exception $e) {
                unset($application);
            }
        }

        if (!isset($application)) { // new application mode
            $application = Application::withUser($user);
            \app\save($application);
            $_SESSION['newAppId'] = $application->id;
            $edit = false;
        }


        $dt = (new DateTime($application->date))->format(DT_FORMAT_SHORT);

        $now = new DateTime();
        $dtMax = $now->format(DT_FORMAT_SHORT);
        $dtMin = $now->modify("-10 months")->format(DT_FORMAT_SHORT);

        // edit app older than 1y
        if ($dtMin > $dt) $dtMin = $dt;

        return AbstractHandler::renderHtml($request, $response, 'nowe-zgloszenie', [
            'config' => [
                'edit' => $edit,
                'lastLocation' => $user->getLastLocation()
            ],
            'app' => $application,
            'dtMin' => $dtMin,
            'dt' => $edit ? $dt : '',
            'dtMax' => $dtMax
        ]);
    }

    public function confirm(Request $request, Response $response): Response {
        $params = (array)$request->getParsedBody();

        $appId = $this->getParam($params, 'applicationId', -1);
        if ($appId == -1) {
            return $this->redirect('/nowe-zgloszenie.html');
        }

        $plateId = $this->getParam($params, 'plateId');

        $dtFromPicture = $this->getParam($params, 'dtFromPicture') == 1; // 1|0 - was date and time extracted from picture?
        $datetime = $this->getParam($params, 'datetime'); // "2018-02-02T19:48:10"
        $comment = $this->getParam($params, 'comment', '');
        $category = (int) $this->getParam($params, 'category');
        $witness = isset($params['witness']);
        $extensions = $this->getParam($params, 'extensions', []); // "6,7", "6", "", missing
        $extensions = array_filter($extensions);

        $fullAddress = json_decode($this->getParam($params, 'address'));
        $fullAddress->addressGPS = $fullAddress->address;
        $fullAddress->address = $this->getParam($params, 'lokalizacja');

        $user = $request->getAttribute('user');

        $application = \app\get($appId);
        global $STATUSES;
        $status = $STATUSES[$application->status];
        if (!$status->editable) {
            logger("Ponowny POST na /potwierdz.html dla zgłoszenia {$application->number} w statusie {$status->name}");
            return $this->redirect("/ud-$appId.html");
        }
        try {
            $application = updateApplication(
                $application,
                $datetime,
                $dtFromPicture,
                $category,
                $fullAddress,
                $plateId,
                $comment,
                $witness,
                $extensions,
                $user,
            );
        } catch (ForbiddenException $e) {
            throw new HttpForbiddenException($request, $e->getMessage(), $e);
        } catch (Exception $e) {
            return $this->redirect('/moje-zgloszenia.html');
        }

        return AbstractHandler::renderHtml($request, $response, 'potwierdz', [
            'config' => [
                'confirmationScreen' => true
            ],
            'app' => $application,
            'vehicleBox' => (isset($application->carInfo->vehicleBox->x)) ? $application->carInfo->vehicleBox : false,
            'user' => $user
        ]);
    }

    /**
     * @SuppressWarnings(PHPMD.Superglobals)
     */
    public function finish(Request $request, Response $response): Response {
        global $STATUSES;
        $params = (array)$request->getParsedBody();

        $appId = $this->getParam($params, 'applicationId', -1);

        if (!isset($_SESSION['newAppId']) || $appId == -1) {
            return $this->redirect('/moje-zgloszenia.html');
        }

        unset($_SESSION['newAppId']);
        $application = \app\get($appId);
        $status = $STATUSES[$application->status];
        if(!$status->editable) {
            logger("Ponowny POST na /dziekujemy.html dla zgłoszenia {$application->number} w statusie {$status->name}");
            return $this->redirect("/ud-$appId.html");
        }
        
        $user = $request->getAttribute('user');

        $edited = $application->hasNumber();

        $application->setStatus("confirmed");
        $application = \app\save($application); // this also sets app number

        $user->setLastLocation($application->getLatLng());
        $user->appsCount = $application->seq;
        \user\save($user);

        \recydywa\update($application->carInfo->plateId);

        \user\stats(false, $user); // update cache

        if ($edited) {
            $application->address->mapImage = null;
        }

        return AbstractHandler::renderHtml($request, $response, 'dziekujemy', [
            'app' => $application,
            'appsCount' => $user->appsCount,
            'edited' => $edited,
            'isPatron' => $user->isPatron()

        ]);
    }

    public function missingSM(Request $request, Response $response): Response {
        $params = $request->getQueryParams();
        $appId = $this->getParam($params, 'id', -1);
        $appCity = '';
        $appNumber = '';
        $address = '';
        if ($appId !== -1) {
            try {
                $app = \app\get($appId);
                $appCity = $app->address->city;
                $appNumber = $app->number;
                $address = $app->getMapUrl();
            } catch (Exception $e) {
            }
        }

        return AbstractHandler::renderHtml($request, $response, 'brak-sm', [
            'appCity' => $appCity,
            'appNumber' => $appNumber,
            'appId' => $appId,
            'address' => $address
        ]);
    }

    public function myApps(Request $request, Response $response): Response {
        $user = $request->getAttribute('user');
        $params = $request->getQueryParams();

        $MY_APPS_PAGE_SIZE = 500;
        $query = $this->getParam($params, 'q', '');
        $status = $this->getParam($params, 'status', 'all');
        $page = max(1, (int) $this->getParam($params, 'page', 1));

        // TODO(search): address fields may be encrypted. Options:
        // 1) Remove address from search and only allow non-encrypted fields.
        // 2) Add a dedicated, denormalized SQL search index for encrypted fields (preferred long-term).
        // 3) Fetch, decrypt, and filter in application code (NOT recommended).
        // Decision intentionally deferred.
        $search = trim($query) === '' ? 'all' : $query;
        $totalCountAll = \user\appsCount($user, 'all', 'all');
        $totalCount = \user\appsCount($user, $status, $search);
        $totalPages = (int) ceil($totalCount / $MY_APPS_PAGE_SIZE);
        if ($totalPages > 0 && $page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $MY_APPS_PAGE_SIZE;
        $applications = \user\apps(user: $user, status: $status, search: $search, limit: $MY_APPS_PAGE_SIZE, offset: $offset);
        $statusCounts = \user\appsCountByStatus($user, $search, $status);

        $countChanged = 0;

        return AbstractHandler::renderHtml($request, $response, 'moje-zgloszenia', [
            'appActionButtons' => true,
            'applications' => $applications,
            'countChanged' => $countChanged,
            'totalCount' => $totalCount,
            'totalCountAll' => $totalCountAll,
            'totalPages' => $totalPages,
            'currentPage' => $page,
            'currentStatus' => $status,
            'statusCounts' => $statusCounts,
            'isAdmin'  => $user->isAdmin(),
            'query' => urldecode($query),
            'general' => [
                'headerLink' => '/moje-zgloszenia.html'
            ]
        ]);
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function applicationPdf(Request $request, Response $response, $args): Response {
        $user = $request->getAttribute('user');
        $appId = $args['appId'];
        $application = \app\get($appId);

        if (!$application->isAppOwner($user))
            return $this->redirect("/ud-$appId.html");

        [$path, $filename] = \app\toPdf($application);
        return AbstractHandler::renderPdf($response, $path, $filename);
    }

    public function myAppsPartial(Request $request, Response $response): Response {
        $user = $request->getAttribute('user');

        $params = $request->getQueryParams();
        $status = $this->getParam($params, 'status', 'all');
        $search = $this->getParam($params, 'search', 'all');
        $limit =  $this->getParam($params, 'limit', 0);
        $offset = $this->getParam($params, 'offset', 0);
        
        $apps = \user\apps($user, $status, $search, $limit, $offset);

        return AbstractHandler::renderHtml($request, $response, 'my-apps-partial', [
            'appActionButtons' => true,
            'applications' => $apps,
            'myAppsSize' => 500,
            'applicationsCount' => count($apps)
        ]);
    }

    public function shipment(Request $request, Response $response): Response {
        return $this->redirect('/moje-zgloszenia.html?update&q=' . urlencode('wysyłka'));
    }

    public function askForStatus(Request $request, Response $response) {
        $sent = \app\sent(31);
        $user = $request->getAttribute('user');

        return AbstractHandler::renderHtml($request, $response, 'zapytaj-o-status', [
            'applications' => $sent,
            'user' => $user
        ]);
    }

    public function applicationShortHtml(Request $request, Response $response, $args) {
        $appId = $args['appId'];
        $application = \app\get($appId);

        return AbstractHandler::renderHtml($request, $response, '_application-short-details', [
            'appActionButtons' => true,
            'app' => $application
        ]);
    }

}
