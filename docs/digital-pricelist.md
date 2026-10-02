# Digitalni XML/CSV cjenik

Modul objavljuje aktualni cjenik trgovine World of Beauty u XML i CSV formatu te čuva nepromjenjive verzije najmanje 30 dana.

## Zakonski rokovi i podaci

Obveza digitalnog cjenika za sve trgovce koji prodaju putem internetske
trgovine počinje **17. studenoga 2026.**, prema
[Pravilniku NN 101/2026](https://narodne-novine.nn.hr/clanci/sluzbeni/2026_09_101_1213.html)
i odgodi iz
[Pravilnika NN 110/2026](https://narodne-novine.nn.hr/clanci/sluzbeni/2026_09_110_1310.html).
Do tog datuma primjenjuje se postojeći djelokrug obveze iz
[Odluke NN 75/2025](https://narodne-novine.nn.hr/clanci/sluzbeni/2025_05_75_979.html).

Cjenik se mora ažurirati svakog radnog dana najkasnije do **08:00**, a svaka
objavljena dnevna verzija mora ostati dostupna najmanje **30 dana**. Prije
produkcijskog uključivanja cron pokretanja treba provjeriti da je raspored
usklađen s lokalnom vremenskom zonom poslužitelja.

Marka/proizvođač i jedinica mjere moraju biti stvarni podaci za pojedini
proizvod. Nedostajuća marka ne smije se zamijeniti nazivom trgovine, a
`kom` se ne smije automatski dodijeliti cijelom mješovitom katalogu. Jedinična
cijena mora se izračunati iz stvarne količine i odgovarajuće jedinice proizvoda
(primjerice €/kg ili €/l), uz primjenu iznimaka iz
[Pravilnika NN 105/2026](https://narodne-novine.nn.hr/clanci/sluzbeni/2026_09_105_1270.html).
Prazna polja iskrenija su od izmišljenih vrijednosti, ali označavaju podatke
koje treba dopuniti prije obvezne produkcijske objave.

Ministarstvo je najavilo i dodatna provedbena pravila, pa prije početka
primjene treba ponovno provjeriti
[službene informacije Ministarstva gospodarstva](https://mingo.gov.hr/print.aspx?id=10447&url=print).

## Aktivacija

1. U administraciji otvorite **Proširenja → Proširenja → Feeds**.
2. Instalirajte **Digitalni XML/CSV cjenik**.
3. Otvorite postavke, provjerite adresu i oznaku prodajnog objekta te spremite.
4. Za redovno osvježavanje postavite prikazani zaštićeni cron URL jednom dnevno prije 08:00.

Prije uključivanja automatske objave provjerite da svaki proizvod ima točnu
marku, jedinicu i jediničnu cijenu ondje gdje su ti podaci obvezni. Zajedničku
jedinicu u postavkama ostavite praznom za mješoviti katalog; ona je primjerena
samo ako dokazano vrijedi za svaki proizvod u izvozu.

Nakon uključivanja, poveznica **Cjenici** prikazuje se uz poveznicu za jednostrani raskid u podnožju trgovine.

## Javne rute

- Stranica i arhiva: `index.php?route=extension/feed/digital_pricelist/page`
- Aktualni XML: `index.php?route=extension/feed/digital_pricelist&format=xml`
- Aktualni CSV: `index.php?route=extension/feed/digital_pricelist&format=csv`

Datoteke i manifest spremaju se izvan javnog web direktorija, u `DIR_STORAGE/digital_pricelist`. Ako postoje stupci `anchor_price` i `anchor_price_date`, uključuju se i sidrena cijena i njezin datum. Cjenik se može generirati i prije primjene migracije sidrenih cijena; tada su ta polja prazna.

Migracija sidrenih cijena dodaje samo potrebne stupce. Ne popunjava povijesne
cijene iz trenutačne cijene proizvoda, jer se provjerene vrijednosti za 2.
svibnja 2025. odnosno 10. rujna 2026. moraju unijeti iz vjerodostojne
evidencije.
