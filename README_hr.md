# HeartPhrame Workspace modul

[English version](README.md)

Workspace modul organizira povezani sadržaj u **Područja** (`Workspaces` na
engleskom). Svako Područje ima svoju putanju, vlasnika, vidljivost, članove,
prava i hijerarhijsko stablo stranica.

## Ovisnosti

Obavezno, redoslijedom uključivanja:

1. `aaieduhr/heartphrame-framework` (`dev-main`)
2. `aaieduhr/heartphrame-module-orm` (`dev-main`)
3. `aaieduhr/heartphrame-module-auth` (`dev-main`)
4. `aaieduhr/heartphrame-module-workspace` (`dev-main`)

Opcionalne integracije:

- HTML Editor daje stranice i uređivanje, a Menu dodaje navigaciju.
- Theme omogućuje izolirane teme Područja i prenosi njihov light/dark izgled u prenosivi izvoz.
- Calendar i Task pretvaraju ugrađene žive podatke u ACL-aware read-only snimke izvoza.
- Notification obavještava recenzente/autore; E-mail može slati kopije.
- API dodaje ACL Workspace resurse i rute za upravljanje stablom.

```bash
composer require aaieduhr/heartphrame-module-workspace:dev-main
vendor/bin/hph workspace:install-migration
vendor/bin/hph orm-migrate:up
```

Strukturirana svojstva stranica koja koristi tablica stranica i svojstava u postojećoj
instalaciji zahtijevaju još jednu migraciju:

```bash
vendor/bin/hph workspace:install-node-properties-migration
vendor/bin/hph orm-migrate:up
```

English documentation: [README.md](README.md)

## Mogućnosti

- ugrađene publike **Javno** i **Svi prijavljeni** uz ograničena Područja
- prava korisnika i grupa: pregled, dodavanje, uređivanje, objavljivanje, brisanje i upravljanje
- asinkrono pretraživanje Auth imenika bez ispisivanja svih korisnika i grupa
- ograničenja po stranici koja nasljeđuju svi potomci
- ACL-filtrirani Sažetci s renderiranim isječcima, razinama, brojem i redoslijedom članaka
- hijerarhijski čvorovi za dokumente, interne i vanjske linkove
- kompaktno responzivno stablo bez okvira koje ispod prve razine otvara samo
  putanju do aktivne stranice
- ACL-sigurna navigacijska putanja od početne stranice kroz vidljive pretke
- povratne poveznice s drugih objavljenih stranica uz ponovnu provjeru prava čitatelja
- sistemski, područni i stranica-specifični zadani prikaz stabla i sadržaja
- kreiranje nove stranice izravno iz otvorenog Područja
- soft delete Područja i administratorsko vraćanje
- opcionalna integracija s HTML editorom za sadržaj, verzije i privitke
- proces objave po stranici i jeziku: nacrt, pregled, objavljeno i arhivirano
- čitatelji i dalje vide zadnju objavljenu nepromjenjivu verziju dok se uređuje nacrt
- opcionalne in-app i e-mail obavijesti za pregled i objavu
- opcionalna Menu integracija za glavni izbornik i Postavke
- javni, prijavljeni i osobni odabir naslovnice aplikacije uz siguran ACL fallback
- opcionalni izbor sistemske teme po Području i izolirana privatna prilagodba teme
- opcionalni verzionirani REST API za podatke područja, ACL i linkove u stablu
- opcionalna Workspace Search integracija za ACL pretragu objavljenog sadržaja
- administratorsko održavanje cijelog sitea ili područja uz pregled prostora,
  sigurno prorjeđivanje povijesti, trajno uklanjanje dovoljno starih obrisanih
  stranica/privitaka te potvrđeno trajno brisanje cijelog soft-obrisanog područja
- neutralni događaji `WorkspaceContentChanged` nakon objave, arhiviranja, brisanja,
  promjene metapodataka stabla i životnog ciklusa Područja kako bi se opcionalni
  izvedeni indeksi sinkronizirali bez vezivanja Workspacea uz implementaciju pretrage
- javni `WorkspaceContentChangeBatch` za velike uvoze: prikuplja pojedinačne
  promjene i šalje jedan završni `bulk_content_changed` događaj po Području
- prijenosna inicijalna shema za SQLite, PostgreSQL i MySQL/MariaDB

Ograničenja stranice mogu samo suziti prava dodijeljena na Području. Ne mogu
dati pristup korisniku ili grupi koji već nemaju prava na Području. Vlasnik
Područja i administratori aplikacije zadržavaju pravo upravljanja. U
arhiviranom Području i njima su isključeni dodavanje, uređivanje i brisanje
sadržaja dok ga ponovno ne aktiviraju.

Za pregled ograničenja uključite **Uredi stablo** i odaberite olovku uz
stranicu. Zeleni checkbox prikazuje pravo naslijeđeno iz Područja, a crveni
pravo zadržano izravnim ograničenjem te stranice. Spremanje bez ijedne crvene
oznake uklanja izravno ograničenje i vraća potpuno nasljeđivanje.

`Javno` je ugrađena publika samo za čitanje. `Svi prijavljeni` također nije
stvarna Auth grupa, ali može dobiti šira prava. Obrazac prikazuje samo
dodijeljene ACL retke; korisnici i grupe dodaju se ograničenom serverskom
pretragom koja ne učitava cijeli imenik.

Zadani prikaz se razrješava redom **stranica → Područje → sistem**. Područje
može naslijediti, prikazati ili sakriti stablo i sadržaj, dok pojedina stranica
može zasebno nadjačati samo prikaz svojega sadržaja.

Obrisani dokumenti nisu odmah fizički uklonjeni: administrator ih može vratiti
sve dok ih izričito ne ukloni kroz **Postavke → Područja → Održavanje** nakon
odabranog razdoblja čuvanja. Detalji i sigurnosna pravila opisani su u
[dokumentaciji održavanja](docs/index_hr.md#13-održavanje-prostora).

## Preduvjeti

- PHP 8.2 ili noviji
- `aaieduhr/heartphrame-framework`
- `aaieduhr/heartphrame-module-auth`
- `aaieduhr/heartphrame-module-orm`

HTML editor, API, Menu, Notification i E-mail modul su opcionalne integracije.

Kada je Menu uključen, Workspace registrira i aktualna navigacijska odredišta u
zajedničkom katalogu editora. Sva četiri editora menija prikazuju aktivna
područja i njihove dokumentne stranice kao `Područje / Stranica`. Menu ih u
odabiru posebnog menija spaja sa svim ostalim navigacijskim stranicama aplikacije
i zapisuje prenosive putanje bez trenutačnog instalacijskog base patha. Menu
ostaje opcionalan, a Workspace ne zapisuje izravno JSON drugog modula.

## API integracija

Workspace objavljuje neutralne opise scopeova `workspace:read` i
`workspace:manage` iz `config/api.php` bez ovisnosti o API modulu. Kada je
instaliran i API modul, uvjetno se izlažu verzionirane Workspace rute ispod
`/api/v1/workspaces`.

`workspace:manage` obuhvaća podatke područja, soft brisanje i vraćanje, ACL,
redoslijed stabla, interne i vanjske link-čvorove te prenosivi HTML izvoz.
Scope čitanja pokriva i objavljene Sažetke. API ne kreira niti briše HTML
dokumente i privitke; oni ostaju odgovornost HTML editora. Svaka operacija
provjerava i scope ključa i efektivni Workspace ACL njegova vlasnika. Široki
scope nikada ne pretvara neovlaštenog korisnika u upravitelja.

Popis ruta i ponašanje odgovora nalaze se u
[docs/index_hr.md](docs/index_hr.md#10-api-integracija).

## Instalacija

```bash
composer require aaieduhr/heartphrame-module-workspace
vendor/bin/hph workspace:install-migration
vendor/bin/hph orm-migrate:up
```

Paket treba dodati nakon Auth i ORM modula u `app.modules.enabled`:

```php
'aaieduhr/heartphrame-module-workspace',
```

Kopirajte `config/workspace.php` u host aplikaciju ako želite promijeniti
zadane vrijednosti.

Migracija ne kreira probno Područje, korisnike, grupe ni stranice.

U aplikaciji koja je već pokrenula stariju Workspace migraciju jednom instalirajte
dodatnu migraciju naslovnice:

```bash
vendor/bin/hph workspace:install-homepage-migration
vendor/bin/hph orm-migrate:up
```

Ako je ta migracija naslovnice već bila primijenjena prije uvođenja
strukturiranih ciljeva Sažetaka, dodajte i kompatibilne stupce prikaza:

```bash
vendor/bin/hph workspace:install-homepage-view-options-migration
vendor/bin/hph orm-migrate:up
```

Za postojeću instalaciju jednom dodajte pohranu privatnih tema Područja:

```bash
vendor/bin/hph workspace:install-themes-migration
vendor/bin/hph orm-migrate:up
```

Postojeća instalacija jednom dodaje i ponovno izgradivi indeks povratnih poveznica:

```bash
vendor/bin/hph workspace:install-backlinks-migration
vendor/bin/hph orm-migrate:up
```

Za čuvanje prenosivih izvornih oznaka iz importa i integracija postojeća
instalacija jednom dodaje pohranu oznaka:

```bash
vendor/bin/hph workspace:install-node-labels-migration
vendor/bin/hph orm-migrate:up
```

Navigacijska putanja gradi se samo iz stabla koje je već filtrirano ACL-om.
Povratne poveznice izdvajaju se iz točnih objavljenih HTML verzija, a pri
svakom prikazu ponovno se provjeravaju ACL stranice i stanje objave. Prednost
ima aktivni jezik, dok se zadani jezik sitea koristi samo ako izvorna stranica
nema zapis poveznice na aktivnom jeziku. Kada je Theme uključen, oba elementa
koriste tematske vrijednosti za navigacijsku putanju, kartice, poveznice,
rubove i prigušeni tekst; Bootstrap fallback održava ih uporabljivima i bez
Theme modula.
Pozadinska obnova koristi uski Editorov servis za čitanje objavljenih verzija
bez web-sesije. Time CLI i skupni import mogu izgraditi izvedeni indeks, dok se
ACL i stanje objave i dalje obavezno provjeravaju pri svakom prikazu.

`workspace_backlinks` i `workspace_backlink_index_state` izvedeni su podaci.
Ne ulaze u backup: događaji objave ih održavaju aktualnima, a periodična
sigurnosna obnova popravlja eventualno propušten događaj.

## Sažetci Područja

Svako vidljivo stablo Područja prikazuje ikonu **Sažetci**. Stranica na
`/{korijen-područja}/{područje}/shorts` renderira točno objavljenu Editor
verziju svake dopuštene stranice kao isječak visine dvanaest redaka s fade
završetkom i poveznicom **Pročitaj više**. Tako ostaje mjesta za približno pet
do šest dodatnih redaka teksta i kada članak počinje kompaktnom slikom. Nacrti, arhivirane objave,
nedostupne stranice i svi potomci nedostupne stranice uklanjaju se prije
učitavanja sadržaja.

Posjetitelj bira samo 1., razine 1–2 ili razine 1–3; 5, 10, 25, 50 ili sve
članke; te hijerarhijski redoslijed, najnovije ili najstarije prvo. **Sve** je
dostupno samo kada manje od 100 članaka prođe provjeru objave i ACL-a. Server
isto pravilo provodi i za ručno sastavljen query string.

Zadane vrijednosti postavljaju se pod **Postavke → Područja** i spremaju u
aplikacijski `config/workspace.php`:

```php
'shorts' => [
    'depth' => 2,
    'limit' => 10,
    'order' => 'newest',
    'display_options_visible' => false,
],
```

Stablo je početno otvoreno, a ploča **Opcije prikaza** sklopljena. Njihovi
uvijek dostupni ikon-gumbi prate temu te imaju pristupačne nazive i opise.
Izravna poveznica može promijeniti bilo koje stanje parametrima `tree=0|1` i
`options=0|1`; obrazac filtra čuva trenutačni odabir posjetitelja. Sadržaj prvo
koristi točno objavljenu verziju aktivnog jezika, a zatim zadani jezik sitea iz
`app.localization.locale` u `config/app.php`; nikada ne koristi nacrt kao
jezični fallback.

To su postavke sitea, a ne dizajn teme. Potpuni backup sitea treba uključiti
`config/workspace.php`; izvoz paketa teme ne preuzima i ne treba preuzimati te
vrijednosti.

## Naslovnica aplikacije

Administrator postavlja naslovnicu u **Postavke → Područja → Naslovnica
aplikacije**. Može odabrati objavljenu stranicu ili prikaz **Sažetaka** za
neprijavljene goste, drugi cilj dostupan svim prijavljenim korisnicima i
dopuštenje osobnog izbora u Auth profilu korisnika. Kada je cilj prikaz
Sažetaka, prikazuju se strukturirani prekidači **Vidljivo stablo stranica** i
**Vidljive opcije prikaza**, a ne slobodno tekstualno polje query parametara.

Za prijavljenog korisnika redoslijed je osobna stranica, zadana za prijavljene,
javna zadana te ugrađena naslovnica host aplikacije. Gost koristi javnu pa
ugrađenu naslovnicu. Svaki zahtjev ponovno provjerava aktualni Workspace ACL i
stanje objave; obrisana, neobjavljena ili naknadno ograničena stranica preskače
se umjesto prikaza greške `403` na naslovnici.

Host aplikacija na ruti `/` može koristiti neutralni servis
`heartphrame.application_homepage_resolver` i napraviti privremeni redirect
bez cacheiranja na kanonsku Workspace stranicu. Auth ne ovisi o Workspaceu:
profilnu sekciju registrira isključivo Workspace dok je uključen.

Postavke i osobni izbori spremaju se u tablice
`workspace_homepage_settings` i `workspace_user_homepages`. Potpuni backup
baze/sitea mora obuhvatiti obje tablice. To su sadržajne postavke sitea i
namjerno ne pripadaju izvozu paketa teme. Vrsta strukturiranog cilja, ID
Područja i oba prekidača vidljivosti spremaju se u te tablice pa ih standardni
backup i povrat baze čuvaju bez gubitka ponašanja naslovnice.

## Teme Područja

Kada je opcionalni Theme modul uključen, svaki upravitelj Područja dobiva
odjeljak **Tema područja** pod **Upravljaj područjem**. **Zadana sistemska tema**
čuva sadašnje globalno ponašanje. Upravitelj može odabrati i bilo koju sistemsku
temu bez njezina kopiranja. Odabrani sistemski JSON ostaje samo za čitanje.

Prva izmjena ili upload asseta stvara privatnu kopiju dostupnu samo tom
Području. Ako upravitelj nije promijenio naziv, nazivu izvorne teme dodaje se
naziv Područja. Konfiguracija je u retku tablice `workspace_themes`, a privatne
slike u `data/workspaces/themes/<workspace-id>/assets`. Druga Područja i
globalne Theme postavke ne vide niti mogu promijeniti tu privatnu kopiju.

Upravitelj može uvesti potpuni Theme v3 ZIP u svoje Područje. Samo administrator
aplikacije dobiva akciju **Izvezi cijelu temu**, pa privatnu temu može prenijeti
u drugo Područje ili globalnu sistemsku biblioteku. Posluživanje privatnih
asseta ponovno provjerava Workspace ACL. Aktivacija vrijedi samo za trenutačni
HTTP zahtjev i nikada ne zapisuje globalni `themes.json` ni `settings.json`.

## Posebni meniji Područja

Kada je opcionalni Menu modul uključen, svaki korisnik s efektivnim Workspace
pravom `can_manage` dobiva **Posebne menije područja** pod **Upravljaj
područjem**. Ekran sadrži neovisne editore posebnog gornjeg i posebnog lijevog
menija. Spremanje ili uklanjanje jednog nikada ne mijenja drugi.

Server oba editora zaključava na `/workspace/{slug}` i njegove podstranice.
Upravitelj smije uređivati nazive i stavke menija, ali izmjenom forme ne može
zahvatiti drugo Područje ni drugu stranicu aplikacije. Sistemske Menu postavke
ostaju dostupne samo administratoru. Bez Menu modula Workspace radi potpuno
samostalno, a poveznica integracije nije prikazana.

## Integracija s HTML editorom

Workspace modul ne sprema HTML. Čvor stabla povezuje sa stabilnim ključem
editorova dokumenta kroz opcionalni servisni most.

Kada su oba modula uključena:

- Workspace putanje i nasljedni ACL upravljaju pristupom povezanom dokumentu;
- samostalna javna slug putanja editora je isključena;
- ovlašteni članovi mogu dodavati, uređivati i brisati povezane stranice;
- obični urednik automatski kreira novi dokument i ne može pogađanjem ključa
  povezati tuđi postojeći dokument; povezivanje postojećih dokumenata dostupno
  je administratoru;
- interne apsolutne putanje razrješavaju se unutar aplikacijskog prefiksa, pa
  `/calendars` radi i kada je aplikacija instalirana pod `/hfc`;
- stranica koristi isti potpuni pregled kao HTML editor: temu, jezike, povijest,
  privitke, ZIP export, sadržaj dokumenta, audit podatke i responzivno ponašanje;
- Workspace dodaje samo lijevo stablo, a efektivni ACL čvora određuje prikaz
  uređivanja, povijesti i ostalih zaštićenih akcija;
- verzije i privitci i dalje pripadaju HTML editoru;
- nova ili promijenjena stranica postaje nacrt, a samo izričita objava mijenja
  nepromjenjivu verziju koju vide čitatelji;
- postoji samo jedan zajednički nacrt po stranici i jeziku; običan pregled uvijek
  pokazuje zadnju objavu, a nacrt se posebno uređuje ili pregledava;
- urednici mogu poslati sadržaj na pregled ili ga vratiti u nacrt, korisnici s
  pravom objavljivanja mogu ga objaviti, a upravitelji arhiviraju i vraćaju stranice;
- slanje na pregled obavještava efektivne objavljivače, a objava korisnika koji
  je nacrt poslao; Notification inbox je primaran, dok E-mail modul može staviti
  opcionalnu SMTP kopiju u red;
- stablo označava nove neobjavljene stranice, a zaglavlje stabla nudi popise novih
  stranica, stranica poslanih na pregled i poveznicu na Sažetke;
- Sažetci nakon filtriranja razina, objave, ACL-a, redoslijeda i količine jednim
  opcionalnim batch pozivom traže točno objavljene Editor verzije;
- jedan editor dokument može pripadati samo jednoj aktivnoj Workspace stranici.

HTML editor nastavlja samostalno raditi kada Workspace modul nije instaliran.
Njegov samostalni pregled uvijek koristi aktualnu verziju editora i ne prikazuje
Workspace kontrole procesa objave.

## Prenosivi HTML izvoz Područja

Administratori i korisnici s efektivnim Workspace pravom `can_manage` mogu
odabrati ikonu **Izvezi područje u HTML** lijevo od gumba **Spremi** na ekranu
**Upravljaj područjem** ili odgovarajuću ikonu u administratorskom popisu **Sva
područja**. Lokalizirani tooltip i pristupačni naziv objašnjavaju radnju. Obrazac izvozi sve dopuštene stranice
ili izričito odabrane stranice. Kod djelomičnog izvoza stablo se ponovno gradi i
sadrži samo HTML stranice koje se zaista nalaze u paketu.

Download je transportni ZIP paket. Nakon raspakiravanja otvara se korijenski
`index.html`; PHP ni web poslužitelj nisu potrebni. Ta je datoteka jedina
tematizirana offline ljuska: zadržava isto zaglavlje, aktivni svijetli/tamni
branding, hero, širinu i preklapanje sadržaja, stablo i karticu dokumenta kao
aplikacija. Lokalno mijenja odabranu stranicu i jezik, u herou prikazuje stvarni
naslov stranice te lokaliziranu napomenu `Izvezeno područje: {Područje}`.
Odabrani logo i hero vizual ugrađeni su u `index.html` kao data URI pa ostaju
vidljivi preko `file://` nakon raspakiravanja na Windowsu, macOS-u ili Linuxu;
njihove izvorne datoteke ostaju u ZIP-u radi pregleda.

Direktorij jezika nastaje samo ako je barem jedna odabrana stranica zaista
objavljena na tom jeziku. Sadržaj se nikada fizički ne kopira u prijevod koji ne
postoji. Svaki stvarni prijevod dobiva jednu stabilnu samostalnu HTML datoteku,
izrađenu istim formatterom kao Editorov izvoz jedne stranice. Može se izravno
otvoriti kroz `file://` i namjerno sadrži samo renderirani dokument, bez
Workspace ljuske i bez pozadine stranice iz Theme modula.
Nativni Editor grafikoni pretvaraju se u responzivni samostalni SVG te
zadržavaju istu širinu, poravnanje, razmak i boje teme kao pregled stranice.

Sigurnosna pravila primjenjuju se prije pakiranja:

- GET obrazac i POST download ruta traže administratora ili efektivno
  `can_manage` pravo;
- uključuju se samo stranice s efektivnim `can_view` pravom i objavljenom,
  nearhiviranom verzijom;
- ručno izmijenjena lista ID-eva ponovno se presijeca s ACL-vidljivim stablom;
- ograničenja pojedine stranice i naslijeđena ograničenja Područja ostaju na snazi;
- Calendar i Task ugradnje renderiraju se kao read-only snimke kroz postojeće
  Editor integracije koje poštuju ACL; paket nema aktivne API pozive,
  vjerodajnice, akcije uređivanja ni serversko stanje.
- dinamički uključene stranice prerenderiraju se uz vlastiti ACL, a paket
  prenosi i njihove slike odnosno privitke potrebne u sadržaju.

Paket sadrži CSS trenutačne teme i samo odabrane svijetle/tamne logo i hero
datoteke, Editor/Calendar/Task CSS, dopuštene privitke, filtrirano stablo,
sadržaje dokumenata i strojno čitljiv `manifest.json`. Offline zaglavlje nudi
sve konfigurirane jezike i izbor svijetle/tamne varijante. Kada stranica nema
točan prijevod odabranog jezika, ljuska prikazuje zadani hrvatski prijevod, a
zatim prvi stvarni prijevod; ne izrađuje lažnu dodatnu datoteku. Stablo, sadržaj
dokumenta i privitci mogu se neovisno pokazati ili sakriti, a početno stanje
slijedi konfiguraciju aplikacije. Theme je opcionalan i bez njega nastaju
minimalno čitljivo zaglavlje, hero i raspored; Editor je potreban za sadržaj
dokumenata.

## Strukturirani sadržaj stranica

Workspace stranice podržavaju oznake i strukturirana svojstva (`tekst`,
`status`, `poveznica`). HTML Editor ih može koristiti u nativnom izvještaju
stranica, a uz to nudi galeriju privitaka, pretragu trenutačnog područja i
nedavne promjene. Svaki dinamički rezultat ponovno se filtrira kroz aktualni
ACL; API, backup/restore i HTML izvoz čuvaju isti ugovor.
Dinamički generirane tablice koriste standardni responzivni HTML za tablice iz
Editora, pa slijede aktivnu temu bez posebnog Workspace vizualnog nadjačavanja.

## Dokumentacija

Detaljna arhitektura i upute razumljive početnicima nalaze se u
[docs/index_hr.md](docs/index_hr.md).
Backup i povrat područja opisani su u [docs/backup_hr.md](docs/backup_hr.md).

## Licenca

Modul je objavljen pod
[European Union Public License (EUPL) v1.2](LICENSE).

## Integracija osobnog praćenja

Workspace objavljuje neutralne događaje promjene s izvršiteljem, područjem,
stranicom, jezikom i razlogom. Kada je uključen
`aaieduhr/simbioza-module-user`, dodaju se kontrole praćenja stranice i područja
te se događaji pretvaraju u ACL-sigurne osobne obavijesti. Workspace ne ovisi o
tom opcionalnom modulu.

## Politika ovisnosti

Framework i interni HeartPhrame moduli zahtijevaju se s pomične grane
`dev-main`. Ovaj modul ne sprema `composer.lock`; CI dohvaća najnovija
razvojna stanja i pokreće cijeli skup provjera `composer on-commit`.
