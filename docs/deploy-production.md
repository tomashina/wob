# Produkcijski deploy

Sve SEO, Consent Mode, GTM, ecommerce, UI i checkout izmjene objavljuju se iz
grane `main`. Produkcijske konfiguracije i slike nisu dio Gita; zasebna SEO
migracija samo kopira slike iz opisa proizvoda u lokalni image direktorij.

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
php scripts/repair-multilingual-seo.php
php scripts/repair-multilingual-seo.php --apply
php scripts/repair-product-description-images.php
php scripts/repair-product-description-images.php --apply
php scripts/disable-empty-catalog.php
php scripts/disable-empty-catalog.php --apply
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
php scripts/repair-multilingual-seo.php
php scripts/repair-multilingual-seo.php --apply
php scripts/repair-product-description-images.php
php scripts/repair-product-description-images.php --apply
php scripts/disable-empty-catalog.php
php scripts/disable-empty-catalog.php --apply
php scripts/apply-seo-ai.php --refresh
```

Prva naredba za višejezični SEO je obavezni dry-run. Pregledajte broj meta
zapisa, zajedničkih HR/EN aliasa i aliasa koji nedostaju prije pokretanja
`--apply`. Apply čuva postojeći zajednički slug kao hrvatski URL, izrađuje novi
engleski slug i sinkronizira HuntBee hreflang OCMOD. Budući da starije OpenCart
tablice koriste MyISAM, prije prve promjene zapisuje recovery JSON izvan web
roota. Ako apply ne uspije, skripta automatski vraća stare vrijednosti; isti se
backup može ručno vratiti naredbom:

```bash
php scripts/repair-multilingual-seo.php --restore=/puna/putanja/do/backupa.json
```

Migracija slika također prvo radi dry-run. Na `--apply` preuzima vanjske slike,
sprema ih pod `image/catalog/seo-description`, dodaje opisni `alt`,
`loading="lazy"` i `decoding="async"`, pa tek onda mijenja opise u bazi. Ako i
jedna slika ima privremenu mrežnu/TLS grešku, baza ostaje netaknuta; trajni HTTP
404/410 uklanja samo neispravan `<img>` tag. Recovery JSON za opise može se ručno
vratiti naredbom:

```bash
php scripts/repair-product-description-images.php --restore=/puna/putanja/do/backupa.json
```

Čišćenje kataloga gasi aktivne proizvode koji nemaju nijednu fizički postojeću
glavnu ili dodatnu sliku. Zatim gasi samo kategorije koje nemaju aktivan proizvod
ni u jednoj podkategoriji. I ova skripta prvo radi dry-run i zapisuje recovery
JSON prije promjene statusa:

```bash
php scripts/disable-empty-catalog.php --restore=/puna/putanja/do/backupa.json
```

Nakon applyja u OpenCart administraciji ponovno generirajte Boost Sitemap
datoteke (product, category i information), a zatim provjerite rezultat:

```bash
php scripts/audit-seo-integrity.php --strict
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
