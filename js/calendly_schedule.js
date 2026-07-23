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
 *
 * Data + settings come from the block: each button carries data-booking-url and
 * data-slot-month/date; prefill and the post-schedule URL come from
 * drupalSettings.calendlyAvailability.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

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
    }
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
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          if (typeof Calendly === 'undefined' || !Calendly.initPopupWidget) {
            // Widget not loaded yet — fall back to navigating to the link.
            window.location.href = btn.getAttribute('href');
            return;
          }
          var base = btn.getAttribute('data-booking-url') || btn.getAttribute('href');
          Calendly.initPopupWidget({
            url: buildUrl(base, btn.getAttribute('data-slot-month'), btn.getAttribute('data-slot-date')),
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
