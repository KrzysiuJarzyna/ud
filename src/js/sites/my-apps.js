

import { updateCounters } from "../lib/status";
import { setStatus } from "../lib/status"
import Api from '../lib/Api'
import { filterable } from "../lib/filterable";
import delay from "../lib/delay";
import makeDropdown from "../lib/dropdown";
import makeDialog from "../lib/dialog";
import sendApplication from "../lib/send";
import { error } from "../lib/toast"

document.addEventListener("DOMContentLoaded", (event) => {
  const appsList = document.getElementById('apps-list')
  if (!appsList) return

  const searchInput = document.getElementById('apps')
  if (searchInput?.dataset.serverSearch === '1') {
    const form = searchInput.closest('form')
    const pageInput = form?.querySelector('input[name="page"]')
    searchInput.addEventListener("input", delay(function () {
      if (!form) return
      if (pageInput) pageInput.value = '1'
      form.submit()
    }, 1200), false)
  } else {
    filterable('apps', 'apps-list')
  }
  makeDialog()

  // app headers clickable
  const appHeaders = appsList?.getElementsByTagName('h3') || []
  for (let h3 of appHeaders)
    h3.addEventListener("click", appClickHandler)

  // close „recydywa” dialong on Esc
  const recydywaElement = document.getElementById('recydywa')
  if (recydywaElement) {
    document.addEventListener('keyup', e => {
      if (e.key === "Escape") {
        recydywaElement.style.display = 'none'
      }
    })
    recydywaElement.addEventListener('click', _e => {
      recydywaElement.style.display = 'none'
    })
  }
  
  updateCounters()
})

export function closeAllApps() {
  const expanded = document.querySelectorAll("#apps-list .expanded")
  for (let app of expanded) {
    app.classList.remove("expanded")
    const appDetailsDiv = app.getElementsByClassName("app-details").item(0)
    // @ts-ignore
    appDetailsDiv.innerHTML = ""
  }
}

async function appClickHandler() {
  appClicked(this.parentElement)
}

/**
 * @param {HTMLElement|null} target
 */
export async function appClicked(target) {
  if (!target) return

  const appId = target.id
  const appDetailsDiv = target.getElementsByClassName("app-details").item(0)

  const close = target.classList.contains("expanded")
  closeAllApps()
  if (close) return // dont expand clicked element

  target.classList.add("expanded")

  // @ts-ignore
  appDetailsDiv.innerHTML = '<div class="loader"></div>'

  const api = new Api(`/short-${appId}-partial.html`)
  const appDetails = await api.getHtml()
  location.hash = `#${appId}`
  // @ts-ignore
  appDetailsDiv.innerHTML = appDetails

  makeDropdown()

  document.querySelectorAll('a.send-application').forEach(link => {
    link.addEventListener('click', async function (e) {
      e.preventDefault()
      
      // Prevent multiple clicks
      if (this.classList.contains('sending')) {
        return
      }
      
      this.classList.add('sending')
      const appId = this.dataset.appid
      
      try {
        await sendApplication(appId)
      } catch (err) {
        console.error('Failed to send application:', err)
        // Error toast is already shown by sendApplication, just log here
      } finally {
        this.classList.remove('sending')
      }
    })
  })

  document.querySelectorAll('a.recydywa-seemore').forEach(link => {
    link.addEventListener('click', async function () {
      const plateId = this.dataset.plateid
      const recydywaElement = document.getElementById('recydywa')
      const recydywaContent = document.querySelector('#recydywa .popup-content')
      if (recydywaContent) recydywaContent.innerHTML = '<div class="loader"></div>'
      if (recydywaElement) recydywaElement.style.display = 'block'
      const api = new Api(`/recydywa-${plateId}-partial.html`)
      const recydywa = await api.getHtml()
      if (recydywaContent) recydywaContent.innerHTML = recydywa
    })
  })

  document.querySelectorAll('.app-field-editable').forEach(field => {
    field.addEventListener('focusout', async function() {
      const initialValue = this.dataset.initialValue ?? ''
      const newValue = this.value ?? ''
      if (initialValue === newValue) {
        return;
      }


      const body = {
        [this.name]: newValue
      };

      this.setAttribute('readonly', "true")
      try {
        const api = new Api(`/api/app/${appId}/fields`)
        const reply = await api.patch(body)
        this.removeAttribute('readonly')
        this.classList.remove("error")
        this.setAttribute("data-initial-value", newValue)

        if (reply.suggestStatusChange) {
          if (confirm('Zgłoszenie ma numer sprawy. Zmienić jego status na „potwierdzone”?')) {
            setStatus(appId, 'confirmed-sm')
          }
        }

        const app = document.getElementById(appId)
        if (app) {
          const oldFilterText = app.getAttribute('data-filtertext') ?? ''
          if (initialValue.length>5) {
            app.setAttribute('data-filtertext', oldFilterText.replace(initialValue, newValue))
          } else {
            app.setAttribute('data-filtertext', oldFilterText + newValue)
          }
        }
      } catch(e) {
        this.removeAttribute('readonly')
        this.classList.add("error")
      }
    })
  })
}
