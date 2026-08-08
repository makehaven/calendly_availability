/**
 * @file
 * Calendly schedule enhancements for the availability block:
 *   1. Open the popup to the SELECTED slot's month (Calendly otherwise always
 *      opens to the current month).
 *   2. Prefill the invitee's name / email / phone so members don't retype what
 *      we already collected.
 *   3. After booking, close the Calendly popup and show a clear "You're booked —
 *      continue to your next step" banner (with a gentle auto-advance), so
 *      members don't get stranded on the confirmation.
 *   4. Forward campaign attribution (utm_*) from the page URL onto the booking
 *      URL, so Calendly records which campaign produced each tour.
 *
 * Data + settings come from the block: each button carries data-booking-url and
 * data-slot-month/date; prefill and the post-schedule URL come from
 * drupalSettings.calendlyAvailability.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  /**
   * Campaign parameters Calendly stores on the invitee's `tracking` object.
   *
   * Without these the whole attribution chain dies one click from the end:
   * makerspace_landing_page tags its /tour link with the campaign, the redirect
   * to /open-tours preserves the query string, and then the booking URL — built
   * from Calendly's bare scheduling_url — dropped it, so every tour booked from
   * a flyer looked identical to a tour booked from nowhere.
   */
  var UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];

  // Survives the visit, not the browser: a member who lands from a campaign and
  // browses a page or two before booking still gets attributed, but the tag
  // does not haunt every future visit from that machine.
  var STORAGE_KEY = 'mhCalendlyCampaign';

  /**
   * Returns campaign params for this visit, preferring the current URL.
   *
   * Storage is only a fallback for later pageviews in the same session, so a
   * fresh campaign click always wins over a stale stored one.
   */
  function campaignParams() {
    var found = {};
    var hasFresh = false;
    try {
      var query = new URLSearchParams(window.location.search);
      UTM_KEYS.forEach(function (key) {
        var value = query.get(key);
        if (value) {
          found[key] = value;
          hasFresh = true;
        }
      });
      if (hasFresh) {
        window.sessionStorage.setItem(STORAGE_KEY, JSON.stringify(found));
        return found;
      }
      var stored = window.sessionStorage.getItem(STORAGE_KEY);
      return stored ? JSON.parse(stored) : {};
    }
    catch (e) {
      // Private mode, disabled storage, or an ancient browser. Attribution is
      // a nice-to-have; never let it break the booking button.
      return hasFresh ? found : {};
    }
  }

  function buildUrl(base, month, date) {
    if (!base) {
      return base;
    }
    var url = base;
    var sep = url.indexOf('?') === -1 ? '?' : '&';
    if (month) {
      url += sep + 'month=' + encodeURIComponent(month);
      sep = '&';
    }
    if (date) {
      url += sep + 'date=' + encodeURIComponent(date);
      sep = '&';
    }
    var campaign = campaignParams();
    UTM_KEYS.forEach(function (key) {
      // Never override a tag the booking URL already carries — an event type
      // configured with its own utm_source in Calendly stays authoritative.
      if (campaign[key] && url.indexOf(key + '=') === -1) {
        url += sep + key + '=' + encodeURIComponent(campaign[key]);
        sep = '&';
      }
    });
    return url;
  }

  function prefillObject() {
    var s = (drupalSettings.calendlyAvailability && drupalSettings.calendlyAvailability.prefill) || {};
    var prefill = {};
    if (s.name) { prefill.name = s.name; }
    if (s.email) { prefill.email = s.email; }
    if (s.phone) {
      // Calendly maps a custom "phone" question to a1; also try its built-in
      // SMS reminder field. Harmless if either isn't present on the event type.
      prefill.customAnswers = { a1: s.phone };
      prefill.smsReminderNumber = s.phone;
    }
    return prefill;
  }

  // Success banner shown after a booking completes.
  function showBooked(continueUrl) {
    if (document.querySelector('.mh-calendly-booked')) {
      return;
    }
    var wrap = document.createElement('div');
    wrap.className = 'mh-calendly-booked';
    wrap.setAttribute('role', 'dialog');
    wrap.setAttribute('aria-live', 'polite');

    var hasNext = !!continueUrl;
    var autoSecs = 10;
    wrap.innerHTML =
      '<div class="mh-calendly-booked__card">' +
        '<div class="mh-calendly-booked__check" aria-hidden="true">✓</div>' +
        '<h2 class="mh-calendly-booked__title">' + Drupal.t("You're booked!") + '</h2>' +
        '<p class="mh-calendly-booked__msg">' + Drupal.t('A calendar invite is on its way to your email.') + '</p>' +
        (hasNext
          ? '<a class="mh-calendly-booked__btn" href="' + continueUrl + '">' + Drupal.t('Continue to your Getting-Started Guide →') + '</a>' +
            '<p class="mh-calendly-booked__auto">' + Drupal.t('Continuing automatically in <span class="mh-calendly-booked__count">@s</span>s…', {'@s': autoSecs}) + '</p>'
          : '<button type="button" class="mh-calendly-booked__btn mh-calendly-booked__close">' + Drupal.t('Done') + '</button>') +
      '</div>';
    document.body.appendChild(wrap);

    var closeBtn = wrap.querySelector('.mh-calendly-booked__close');
    if (closeBtn) {
      closeBtn.addEventListener('click', function () { wrap.remove(); });
    }
    if (hasNext) {
      var remaining = autoSecs;
      var countEl = wrap.querySelector('.mh-calendly-booked__count');
      var timer = window.setInterval(function () {
        remaining -= 1;
        if (countEl) { countEl.textContent = remaining; }
        if (remaining <= 0) {
          window.clearInterval(timer);
          window.location.href = continueUrl;
        }
      }, 1000);
      // Cancel the auto-advance if the member interacts (e.g. wants to re-read).
      wrap.addEventListener('click', function (e) {
        if (e.target.classList.contains('mh-calendly-booked__btn')) {
          return; // let the link navigate
        }
        window.clearInterval(timer);
        var auto = wrap.querySelector('.mh-calendly-booked__auto');
        if (auto) { auto.remove(); }
      });
    }
  }

  Drupal.behaviors.mhCalendlySchedule = {
    attach: function (context) {
      // Open the popup to the right month, with prefill.
      once('mh-calendly-btn', '.calendly-schedule-button', context).forEach(function (btn) {
        // Remember the untouched booking URL before rewriting href below, so
        // repeat attaches never stack month/date/utm onto an already-built URL.
        if (!btn.getAttribute('data-booking-url')) {
          btn.setAttribute('data-booking-url', btn.getAttribute('href'));
        }
        var base = btn.getAttribute('data-booking-url');
        var full = buildUrl(base, btn.getAttribute('data-slot-month'), btn.getAttribute('data-slot-date'));
        // Stamp the href too: middle-click, "open in new tab", and the no-popup
        // fallback below all navigate rather than going through the widget, and
        // each of those should still carry the campaign.
        btn.setAttribute('href', full);

        btn.addEventListener('click', function (e) {
          e.preventDefault();
          if (typeof Calendly === 'undefined' || !Calendly.initPopupWidget) {
            // Widget not loaded yet — fall back to navigating to the link.
            window.location.href = full;
            return;
          }
          Calendly.initPopupWidget({
            url: full,
            prefill: prefillObject()
          });
        });
      });

      // Handle the post-booking hand-off once per page.
      once('mh-calendly-scheduled', 'body', context).forEach(function () {
        window.addEventListener('message', function (e) {
          if (!e.data || typeof e.data !== 'object' || e.data.event !== 'calendly.event_scheduled') {
            return;
          }
          var next = (drupalSettings.calendlyAvailability && drupalSettings.calendlyAvailability.postScheduleUrl) || '';
          // Give Calendly a beat to register the booking, then close + hand off.
          window.setTimeout(function () {
            if (typeof Calendly !== 'undefined' && Calendly.closePopupWidget) {
              Calendly.closePopupWidget();
            }
            showBooked(next);
          }, 400);
        });
      });
    }
  };

})(Drupal, drupalSettings, once);
