# Produkcijski deploy

Sve SEO, Consent Mode, GTM, ecommerce, UI i checkout izmjene objavljuju se iz
grane `main`. Produkcijske konfiguracije i slike nisu dio Gita i deploy ih ne
mijenja.

## Prije prvog deploya ovog paketa

Napravite sigurnosnu kopiju baze. Ako je produkcija još na commitu `5c43b1f`,
stara OCMOD mapa može sadržavati lokalno generirane izmjene. Sljedeća naredba
vraća samo tu generiranu mapu, povlači `main`, postavlja GTM/privolu i na kraju
jednom osvježava OCMOD i Twig cache:

```bash
cd /home/dmb/worldofbeauty.hr
git restore --source=HEAD --staged --worktree storagedijana/storagedijana/modification
git pull --ff-only origin main
php scripts/apply-tracking-consent.php
php scripts/apply-home-salon-gateway.php
php scripts/apply-seo-ai.php --refresh
git status --short
```

Zadnja naredba ne treba ništa ispisati. Produkcijski `upload/config.php`,
`upload/admin/config.php`, `upload/env.php` i `upload/image/` ostaju netaknuti.

## Svaki sljedeći deploy

Nakon prvog deploya generirana OCMOD mapa više nije pod Gitom, pa je dovoljno:

```bash
cd /home/dmb/worldofbeauty.hr
git pull --ff-only origin main
php scripts/apply-tracking-consent.php
php scripts/apply-home-salon-gateway.php
php scripts/apply-seo-ai.php --refresh
```

## Brza provjera nakon deploya

1. Otvorite naslovnicu, jednu kategoriju, jedan proizvod, košaricu i checkout u
   privatnom prozoru.
2. Provjerite da se prikazuje samo novi cookie dijalog i da ikona postavki
   ponovno otvara privolu.
3. U GTM Previewu provjerite događaje `view_item`, `add_to_cart`, `view_cart`,
   `begin_checkout`, `add_shipping_info`, `add_payment_info` i `purchase`.
4. Provjerite `robots.txt`, `sitemap.xml` i ponovite PageSpeed test nakon što se
   produkcijski image/WebP cache zagrije.
