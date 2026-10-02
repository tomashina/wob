# Sidrene cijene

Implementacija dodaje sidrenu cijenu i referentni datum proizvodima u
OpenCartu 3.0.3.8. Vrijednosti se mogu uređivati na obrascu proizvoda ili
masovno uvesti iz popisa proizvoda.

## Referentni datumi i pravna napomena

Referentni datum ne smije se određivati samo prema današnjoj cijeni proizvoda:

- za kozmetiku, toaletne proizvode i ostale proizvode već obuhvaćene
  [Odlukom NN 75/2025](https://narodne-novine.nn.hr/clanci/sluzbeni/2025_05_75_979.html)
  prikazuje se redovna prodajna cijena koja je vrijedila **2. svibnja 2025.**
- za proizvode koji u obvezu ulaze tek proširenjem iz
  [Odluke NN 101/2026](https://narodne-novine.nn.hr/clanci/sluzbeni/2026_09_101_1212.html)
  referentna je redovna prodajna cijena koja je vrijedila **10. rujna 2026.**;
  primjena proširenja odgođena je do **17. studenoga 2026.**
  [Odlukom NN 110/2026](https://narodne-novine.nn.hr/clanci/sluzbeni/2026_09_110_1309.html)
- kategorije koje već koriste datum 2. svibnja 2025. zadržavaju taj datum i
  nakon 17. studenoga 2026.; za njih se datum ne mijenja na 10. rujna 2026.

Najniža cijena primijenjena tijekom 30 dana prije sniženja zasebna je obveza
kod objave sniženja. Ona nije sidrena cijena i ne smije se zamijeniti poljima
`anchor_price` i `anchor_price_date`. Dodatna službena pojašnjenja dostupna su
na [stranici Ministarstva gospodarstva](https://mingo.gov.hr/print.aspx?id=10440&url=print).

## Aktivacija

Nakon prijenosa datoteka pokrenite idempotentnu migraciju:

```bash
mysql -u KORISNIK -p NAZIV_BAZE < database/migrations/20261002_anchor_prices.sql
```

Migracija dodaje stupce `anchor_price` i `anchor_price_date`, ali ih namjerno ne
popunjava iz današnje `product.price`. Današnja cijena nije dokaz povijesne
cijene na zakonski referentni datum. Nakon migracije provjerene vrijednosti
unesite ručno ili CSV uvozom; prazne vrijednosti ne prikazuju se kupcima.
Ponovno pokretanje migracije ne prepisuje ručne izmjene ni namjerno obrisane
vrijednosti.

Zatim u administraciji otvorite **Extensions > Modifications** i osvježite
OCMOD cache.

## CSV uvoz

Na popisu proizvoda gumb za preuzimanje daje CSV predložak, a susjedni gumb
otvara uvoz. Datoteka može koristiti zarez ili točka-zarez. Obvezni su:

- jedan identifikator: `product_id`, `model`, `sku` ili `ean`
- `anchor_price`
- `anchor_price_date` u obliku `YYYY-MM-DD`

Primjer:

```csv
model;anchor_price;anchor_price_date
KOZ-001;12,50;2025-05-02
OPR-002;18.90;2026-09-10
```

Drugi datum u primjeru primjenjiv je samo na proizvode koji nisu bili
obuhvaćeni ranijom odlukom, a u proširenu obvezu ulaze 17. studenoga 2026.
Prije uvoza za svaki proizvod treba provjeriti kategoriju i stvarnu redovnu
cijenu koja je vrijedila na odgovarajući referentni datum.

Vrijednost `0` uz prazan datum briše postojeću sidrenu cijenu. Naknadno
pokretanje migracije neće je ponovno popuniti.

Iznos se validira i sprema kao točna decimalna vrijednost bez pretvorbe kroz
PHP `float`, kao OpenCart osnovna cijena bez poreza jednako kao polje
`product.price`; trgovina ga prikazuje s pripadajućim porezom i valutom.
Cijela datoteka validira se prije upisa pa pogrešan red ne uzrokuje djelomičan
uvoz. Najviše je dopušteno 10.000 podatkovnih redova i datoteka od 2 MB.
Uvoz pokrenut iz Product Quick Edit prikaza vraća se u isti prikaz i osvježava
njegov cache proizvoda.

## Prikaz u trgovini

Sidrena cijena prikazuje se na stranici proizvoda, brzom pregledu, kategoriji,
pretrazi, proizvođaču, akcijama i povezanim proizvodima te u aktivnim Basel i
standardnim modulima proizvoda. Isti podaci dodaju se i AJAX odgovoru filtra,
pa ponovno učitana lista koristi isti prikaz kao početna stranica kategorije.
Formatiranje poreza, valute, datuma i oznake zajedničko je svim površinama.

Automatizirana provjera CSV parsera pokreće se naredbom:

```bash
php scripts/test-anchor-prices.php
```
