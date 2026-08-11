# Google Tag Manager i Consent Mode

World of Beauty koristi Google Tag Manager spremnik `GTM-K6DBPBNM` i vlastiti
CookieConsent v3 dijalog s kategorijama **Nužni**, **Analitika** i
**Marketing**.

Consent Mode v2 postavlja početno stanje prije GTM isječka. Na prvom posjetu
`analytics_storage`, `ad_storage`, `ad_user_data` i `ad_personalization` imaju
vrijednost `denied`. Nakon izbora posjetitelja stanje se ažurira odmah, bez
ponovnog učitavanja stranice. Na sljedećem posjetu spremljeni `cc_cookie`
primjenjuje se sinkrono prije učitavanja GTM-a.

## Postavljanje nakon uvoza baze

Pokrenite:

```bash
php scripts/apply-tracking-consent.php --refresh
```

Skripta dodaje GTM polje u postojeći **Complete Google Analytics + GA4** modul,
postavlja spremnik, prazni izravna GA/GA4 polja radi sprječavanja dvostrukog
brojenja te isključuje stari GDPR i Basel cookie banner.

GTM spremnik može se naknadno promijeniti u administraciji kroz modul
**Google Analytics + GA4 + Google Tag Manager**. Upišite samo ID u formatu
`GTM-XXXXXXX`.

## GTM postavke

Google tagovi trebaju koristiti ugrađene consent provjere. Nemojte im dodavati
dodatne blokirajuće consent triggere. Za oznake koje nisu Googleove u GTM-u
postavite odgovarajuće dodatne consent provjere ili ih aktivirajte na događaj
`cookie_consent_update` prema vrijednostima `consent_analytics` i
`consent_marketing`.

Ako je GA4 postavljen unutar GTM-a, polja **GA ID** i **GA4 ID** u OpenCart
modulu moraju ostati prazna.

## Ecommerce događaji

Trgovina šalje GA4 ecommerce podatke s valutom, vrijednošću, artiklima,
jediničnom cijenom i količinom. Pokriveni su sljedeći događaji:

- `view_item` i `view_item_list`
- `search`
- `add_to_wishlist`
- `add_to_cart` i `remove_from_cart`
- `view_cart`
- `begin_checkout`
- `add_shipping_info` i `add_payment_info`
- `purchase`

QuickCheckout događaji za dostavu i plaćanje šalju se odmah nakon AJAX
odabira. Isti nepromijenjeni odabir ne šalje se ponovo. `purchase` se šalje na
stranici uspješne narudžbe i pamti ID narudžbe u sesiji kako ponovno
učitavanje stranice ne bi udvostručilo konverziju.

Vrijednost `purchase` događaja predstavlja prihod od artikala bez poreza i
dostave; porez i dostava šalju se u zasebnim GA4 parametrima.

## Provjera

1. U privatnom prozoru otvorite trgovinu i provjerite da se prikazuje novi
   dijalog s tri kategorije.
2. U Tag Assistantu provjerite početne Consent Mode v2 vrijednosti `denied`.
3. Prihvatite sve i provjerite da se iste vrijednosti mijenjaju u `granted` na
   istoj stranici.
4. Ponovno otvorite postavke kroz poveznicu u podnožju ili plutajući gumb i
   provjerite povlačenje privole.
5. U GTM Preview načinu provjerite ecommerce događaje i Google Ads konverziju.

Za potpuno novi test izbrišite kolačić `cc_cookie` ili otvorite novi privatni
prozor.

Detaljan postupak objave nalazi se u
[`docs/deploy-google-tracking.md`](deploy-google-tracking.md).
