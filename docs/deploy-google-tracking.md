# Deploy Google Tag Managera, privole i ecommerce praćenja

Za kompletan aktualni deploy (uključujući SEO, strukturirane podatke i UI)
koristite [`deploy-production.md`](deploy-production.md). Ovaj dokument ostaje
detaljna GTM i Consent Mode kontrolna lista.

Ove izmjene treba objaviti kao jednu cjelinu jer QuickCheckout kontroleri i
njihovi predlošci zajedno mijenjaju AJAX odgovor za odabir dostave i plaćanja.

## 1. Prije objave

1. Napravite sigurnosnu kopiju produkcijske baze i trenutačne verzije koda.
2. Provjerite da su produkcijski `upload/config.php`,
   `upload/admin/config.php`, `upload/env.php` i `upload/image/` sačuvani. Ti su
   resursi vezani uz okruženje i ne smiju se zamijeniti lokalnim verzijama.
3. Ako se datoteke prenose ručno, koristite manifest u nastavku. Kontrolere i
   Twig predloške za QuickCheckout prenesite u istom releaseu.

## 2. Objavljivanje koda

Kod Git deploya, iz korijena projekta na serveru pokrenite:

```bash
git pull --ff-only origin main
```

Kod SFTP/FTP deploya ne prenosite lokalne konfiguracijske datoteke, slike ni
sadržaj runtime mapa `cache/`, `logs/`, `session/` i `upload/` unutar
`storagedijana/storagedijana/`. Generirane OCMOD datoteke nije potrebno ručno
prenositi jer će ih OpenCart ponovo izraditi u sljedećem koraku.

Runtime manifest za ručni prijenos:

```text
upload/admin/controller/extension/cmpltguagaf.php
upload/admin/model/extension/cmpltguagaf.php
upload/admin/view/template/extension/cmpltguagaf.twig
upload/admin/language/en-gb/extension/cmpltguagaf.php
upload/admin/language/english/extension/cmpltguagaf.php
upload/admin/language/hr-HR/extension/cmpltguagaf.php
upload/catalog/controller/common/footer.php
upload/catalog/controller/common/header.php
upload/catalog/controller/extension/quickcheckout/checkout.php
upload/catalog/controller/extension/quickcheckout/payment_method.php
upload/catalog/controller/extension/quickcheckout/shipping_method.php
upload/catalog/language/en-gb/common/footer.php
upload/catalog/language/hr-HR/common/footer.php
upload/catalog/model/extension/cmpltguagaf.php
upload/catalog/view/theme/basel/template/common/footer.twig
upload/catalog/view/theme/basel/template/common/header.twig
upload/catalog/view/theme/basel/template/extension/quickcheckout/checkout.twig
upload/catalog/view/theme/basel/template/extension/quickcheckout/payment_method.twig
upload/catalog/view/theme/basel/template/extension/quickcheckout/shipping_method.twig
upload/catalog/view/javascript/cookieconsent/cookieconsent.css
upload/catalog/view/javascript/cookieconsent/cookieconsent.umd.js
upload/catalog/view/javascript/cookieconsent/wob-consent.css
upload/catalog/view/javascript/cookieconsent/wob-consent.js
scripts/apply-tracking-consent.php
```

## 3. Postavljanje baze i osvježavanje OpenCarta

Iz korijena produkcijskog projekta pokrenite:

```bash
php scripts/apply-tracking-consent.php
php scripts/apply-seo-ai.php --refresh
```

Skripte su idempotentne i:

- postavlja `GTM-K6DBPBNM`;
- prazni stara izravna GA/GA4 polja kako ne bi došlo do dvostrukog mjerenja;
- isključuje stari GDPR i Basel cookie banner;
- primjenjuje SEO/AI postavke te osvježava OCMOD modifikacije i Twig cache.

Ispis uključuje sljedeće potvrde (između njih se ispisuju i SEO provjere):

```text
UPDATED Google Tag Manager GTM-K6DBPBNM
UPDATED legacy GDPR cookie banner disabled
UPDATED legacy Basel cookie bar disabled
REFRESHED OCMOD and theme template cache
```

Ako automatsko osvježavanje nije dostupno, pokrenite
`php scripts/apply-seo-ai.php` bez `--refresh`, zatim u OpenCart administraciji
ručno osvježite:

1. **Extensions > Modifications > Refresh**
2. **Dashboard > Developer Settings > Theme cache > Refresh**

Nakon toga po potrebi očistite PHP OPcache i CDN/page cache koji koristi
produkcijski server.

## 4. GTM objava

Deploy web-stranice ne objavljuje GTM workspace. U spremniku
`GTM-K6DBPBNM` provjerite Google tag `G-NWBLM45GXK`, okidače za ecommerce
događaje i Google Ads oznaku konverzije, a zatim zasebno objavite GTM verziju
ako postoje neobjavljene promjene.

Googleove oznake trebaju koristiti njihove ugrađene provjere privole. Nemojte
im dodavati dodatni blokirajući consent trigger.

## 5. Provjera nakon objave

1. Otvorite produkciju u novom privatnom prozoru. Mora se prikazati samo novi
   WOB dijalog za privolu.
2. U Tag Assistant Previewu provjerite da su prije izbora
   `analytics_storage`, `ad_storage`, `ad_user_data` i `ad_personalization`
   postavljeni na `denied`.
3. Odaberite samo nužne kolačiće, a zatim prihvatite sve i provjerite promjenu
   vrijednosti u `granted` bez ponovnog učitavanja stranice.
4. Napravite testni prolaz kroz trgovinu i potvrdite događaje `view_item`,
   `add_to_cart`, `view_cart`, `begin_checkout`, `add_shipping_info`,
   `add_payment_info` i `purchase`.
5. Na `purchase` događaju provjerite jedinstveni `transaction_id`, valutu,
   artikle, količine, vrijednost, porez i dostavu. Osvježavanje success stranice
   ne smije poslati drugi `purchase`.
6. Nakon testa provjerite GA4 DebugView i Google Ads dijagnostiku konverzije.

## Povrat na prethodnu verziju

Vratite prethodni release koda i ponovo osvježite OCMOD i theme cache. Dodano
`gtmid` polje u bazi može ostati; za hitno zaustavljanje praćenja dovoljno je
isključiti modul **Google Analytics + GA4 + Google Tag Manager** u OpenCart
administraciji.
