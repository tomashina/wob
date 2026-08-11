(function () {
  'use strict';

  function applyConsent() {
    var analytics = CookieConsent.acceptedCategory('analytics');
    var marketing = CookieConsent.acceptedCategory('marketing');

    if (typeof window.gkSetGoogleConsent === 'function') {
      window.gkSetGoogleConsent(analytics, marketing);
    }
  }

  function boot() {
    if (!window.CookieConsent || window.gkCookieConsentStarted) return;

    window.gkCookieConsentStarted = true;

    var english = document.documentElement.lang &&
      document.documentElement.lang.toLowerCase().indexOf('en') === 0;
    var privacyHref = english ? 'privacy-policy' : 'pravila-privatnosti';

    CookieConsent.run({
      revision: 1,
      cookie: {name: 'cc_cookie', expiresAfterDays: 182, sameSite: 'Lax'},
      disablePageInteraction: true,
      guiOptions: {
        consentModal: {
          layout: 'box',
          position: 'middle center',
          equalWeightButtons: true,
          flipButtons: false
        },
        preferencesModal: {layout: 'box', position: 'middle center'}
      },
      categories: {
        necessary: {enabled: true, readOnly: true},
        analytics: {
          enabled: false,
          autoClear: {cookies: [{name: /^_ga/}, {name: '_gid'}, {name: '_gat'}]}
        },
        marketing: {
          enabled: false,
          autoClear: {
            cookies: [
              {name: /^_gcl/},
              {name: '_fbp'},
              {name: '_fbc'}
            ]
          }
        }
      },
      onConsent: applyConsent,
      onChange: applyConsent,
      language: {
        default: english ? 'en' : 'hr',
        translations: {
          hr: {
            consentModal: {
              title: 'Vaša privatnost, vaš izbor',
              description: 'Nužni kolačići omogućuju rad web trgovine. Uz vaše dopuštenje koristimo analitičke i marketinške oznake za poboljšanje stranice i mjerenje konverzija. <a href="' + privacyHref + '">Pravila privatnosti</a>.',
              acceptAllBtn: 'Prihvati sve',
              acceptNecessaryBtn: 'Samo nužni',
              showPreferencesBtn: 'Postavke'
            },
            preferencesModal: {
              title: 'Postavke kolačića',
              acceptAllBtn: 'Prihvati sve',
              acceptNecessaryBtn: 'Samo nužni',
              savePreferencesBtn: 'Spremi odabir',
              closeIconLabel: 'Zatvori',
              sections: [
                {
                  title: 'O vašem izboru',
                  description: 'Izbor možete promijeniti u bilo kojem trenutku poveznicom „Postavke kolačića” u podnožju stranice.'
                },
                {
                  title: 'Nužni kolačići',
                  description: 'Omogućuju košaricu, prijavu, jezik, valutu, sigurnost i pamćenje vašeg izbora. Ne mogu se isključiti.',
                  linkedCategory: 'necessary'
                },
                {
                  title: 'Analitika',
                  description: 'Google Analytics pomaže nam razumjeti korištenje trgovine i poboljšati sadržaj.',
                  linkedCategory: 'analytics'
                },
                {
                  title: 'Marketing',
                  description: 'Google Ads i ostale marketinške oznake služe mjerenju konverzija, učinkovitosti oglasa i prilagodbi oglašavanja.',
                  linkedCategory: 'marketing'
                }
              ]
            }
          },
          en: {
            consentModal: {
              title: 'Your privacy, your choice',
              description: 'Necessary cookies keep the shop working. With your permission we use analytics and marketing tags to improve the site and measure conversions. <a href="' + privacyHref + '">Privacy policy</a>.',
              acceptAllBtn: 'Accept all',
              acceptNecessaryBtn: 'Necessary only',
              showPreferencesBtn: 'Settings'
            },
            preferencesModal: {
              title: 'Cookie settings',
              acceptAllBtn: 'Accept all',
              acceptNecessaryBtn: 'Necessary only',
              savePreferencesBtn: 'Save selection',
              closeIconLabel: 'Close',
              sections: [
                {
                  title: 'About your choice',
                  description: 'You can change your choice at any time using the “Cookie settings” link in the footer.'
                },
                {
                  title: 'Necessary cookies',
                  description: 'Required for the cart, login, language, currency, security and saving your choice. They cannot be disabled.',
                  linkedCategory: 'necessary'
                },
                {
                  title: 'Analytics',
                  description: 'Google Analytics helps us understand how the shop is used and improve its content.',
                  linkedCategory: 'analytics'
                },
                {
                  title: 'Marketing',
                  description: 'Google Ads and other marketing tags measure conversions, advertising performance and ad personalisation.',
                  linkedCategory: 'marketing'
                }
              ]
            }
          }
        }
      }
    });
  }

  document.addEventListener('click', function (event) {
    var trigger = event.target.closest('[data-cookie-consent-trigger]');
    if (!trigger) return;

    event.preventDefault();
    if (window.CookieConsent) CookieConsent.showPreferences();
  });

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
}());
