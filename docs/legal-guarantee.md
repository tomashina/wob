# Zakonsko jamstvo

Modul prikazuje službenu obavijest o zakonskom jamstvu u zaglavlju i podnožju
trgovine, na potvrdi kupnje te u potvrdi narudžbe e-poštom. Slika se u e-pošti
šalje i kao privitak kako bi obavijest ostala dostupna kada klijent blokira
udaljene slike.

Službena slika nalazi se u:

```text
upload/image/catalog/legal/eu-legal-guarantee-hr.png
```

Poveznica na dodatne informacije vodi na službenu stranicu Europske unije:

```text
https://europa.eu/youreurope/citizens/consumers/shopping/guarantees/index_hr.htm
```

Nakon prijenosa datoteka potrebno je osvježiti OCMOD i Twig cache. U lokalnom
okruženju to se može napraviti naredbom:

```bash
php scripts/refresh-ocmod.php
```

Automatizirana provjera integracije i izvornog asseta pokreće se naredbom:

```bash
php scripts/test-legal-guarantee.php
```
