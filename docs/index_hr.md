# Vodič za Workspace modul

English version: [index_en.md](index_en.md)

## Proširenja prikaza

Izvedeni moduli mogu registrirati implementaciju sučelja
`WorkspacePresentationProviderInterface` u `WorkspacePresentationRegistry`.
Registry grupno prilagođava već učitane retke područja za aktivni ili izričito
zadani jezik. Provider smije mijenjati samo prikazne vrijednosti i ne smije
pisati u Workspace tablice.

## 1. Mentalni model

Područje je organizacijska i sigurnosna granica iznad pojedinih stranica.
Područje je vlasnik stabla stranica i prava, dok specijalizirani moduli ostaju
vlasnici svojeg sadržaja.

Primjerice, čvor dokumenta sprema `document_key` HTML editora, a HTML editor
i dalje sprema HTML verzije i metapodatke privitaka. Čvorovi linkova sadrže
internu rutu/putanju ili vanjski HTTPS URL i ne ovise o editoru.

Takav dizajn sprječava Workspace modul da izravno čita privatne tablice drugog
modula.

## 2. Model podataka

Jedina inicijalna migracija kroz ORM kreira osam tablica:

| Tablica | Odgovornost |
| --- | --- |
| `workspaces` | Identitet, slug, vidljivost, arhiva i soft delete |
| `workspace_acl` | Prava korisnika/grupa na razini Područja |
| `workspace_nodes` | Uređena hijerarhija dokumenata i linkova |
| `workspace_node_acl` | Dodatna ograničenja naslijeđena kroz stablo |
| `workspace_node_workflows` | Stanje objave i pokazivači na nepromjenjive Editor verzije po stranici i jeziku |
| `workspace_homepage_settings` | Javna i prijavljena politika naslovnice aplikacije |
| `workspace_user_homepages` | Opcionalni osobni izbori naslovnice |
| `workspace_themes` | Sistemski izbor ili izolirani privatni JSON teme po Području |

Nema SQL-a vezanog uz određenu bazu. Boolean zadane vrijednosti su stvarni
booleani, a shema je kompatibilna sa SQLite, PostgreSQL i MySQL/MariaDB bazama.

Migracija namjerno ne sadrži testne podatke.

## 3. Vidljivost Područja

Vidljivost se podešava u istoj ACL tablici kao i ostala prava. Dvije ugrađene
publike izgledaju kao grupe, ali nisu Auth grupe i ne stvaraju se u bazi
korisničkih grupa:

- **Javno** (`public`) obuhvaća goste i prijavljene korisnike te uvijek daje
  isključivo pregled. Dodavanje, uređivanje, brisanje i upravljanje za tu su
  publiku namjerno onemogućeni.
- **Svi prijavljeni** (`authenticated`) obuhvaća svakog prijavljenog korisnika
  i može dobiti pregled te šira prava koja odabere upravitelj Područja.
- Kada nije dodana nijedna ugrađena publika, Područje je `restricted` i vide ga
  samo administratori te izričito ovlašteni korisnici ili Auth grupe.

Stupac `workspaces.visibility` čuva sažeto stanje radi brzog filtriranja
Područja, ali se automatski sinkronizira iz ugrađenih ACL redaka. Zato obrazac
nema drugi, odvojeni odabir vidljivosti koji bi se mogao razići s pravima.

Arhivirano Područje ostaje čitljivo ovlaštenim korisnicima, ali su promjene
sadržaja isključene i upravitelju i administratoru. Njihovo `can_manage` pravo
ostaje aktivno kako bi mogli ponovno uključiti Područje. Brisanje Područja je
soft delete. Administratori mogu vidjeti i vratiti obrisana Područja te riješiti
konflikt sluga.

## 4. Prava i nasljeđivanje

Workspace ACL prihvaća pojedinačne korisnike, stvarne Auth grupe te ugrađene
publike `public` i `authenticated`. Prava trenutačnog korisnika, ugrađenih
publika kojima pripada i svih njegovih grupa se zbrajaju:

- `can_view`
- `can_add`
- `can_edit`
- `can_publish`
- `can_delete`
- `can_manage`

`can_manage` uključuje sva ostala prava. Administratori aplikacije dobivaju
potpuni skup prava. Kreator novog Područja dobiva običan korisnički ACL red s
`can_manage`, bez posebnog statusa vlasnika.

Ekran ne učitava sve korisnike i grupe. Prikazuje samo već dodijeljene ACL
retke, a novi se subjekt dodaje pretraživačem. Pretraga se izvršava na serveru,
prihvaća dio prikaznog imena, korisničke oznake ili naziva grupe i vraća najviše
20 rezultata po zahtjevu. Prethodni zahtjev prekida se kada korisnik nastavi
pisati. Takav obrazac ostaje uporabiv i kada Auth imenik sadrži tisuće
korisnika i stotine grupa, bez slanja cijelog imenika u HTML.

Uklanjanje retka iz tablice uklanja samo dodjelu prava nakon spremanja; ne briše korisnika
ni grupu iz Auth modula.

ACL čvora namjerno samo ograničava:

1. izračunaju se efektivna prava Područja;
2. prolazi se put od korijenskog do traženog čvora;
3. kada predak ima ograničenja, zadržavaju se samo prava dopuštena već
   ovlaštenom korisniku ili njegovim već ovlaštenim grupama;
4. pravo Područja nikada se ne može proširiti kroz stranicu.

Prazan ACL čvora znači “naslijedi bez dodatnog ograničenja”. Ograničenje
roditeljske stranice automatski vrijedi za sve potomke.

U otvorenom Području uključite **Uredi stablo** pa odaberite olovku uz čvor.
Modal zeleno prikazuje prava naslijeđena iz Područja i nadređenih stranica, a crveno izravna
ograničenja stranice. Crvena oznaka može samo zadržati pravo koje već postoji
zeleno; nikada ga ne može proširiti. Uklanjanje svih crvenih oznaka i spremanje
vraća potpuno nasljeđivanje. Korisnik s `can_edit`, ali bez `can_manage`, smije
pregledati matricu, dok je mijenjati smije samo `can_manage`. Modal se premješta
izravno pod body dokumenta kako ga stacking context teme ili Hero elementa ne
bi smjestio ispod Bootstrap backdroppa.

Kod prikaza velikog stabla modul grupno učitava čvorove, Workspace ACL,
korisnikove grupe, ograničenja čvorova i workflow stanja. Lanci predaka i
efektivna prava zatim se računaju u memoriji. Time broj ORM upita ostaje
približno stalan umjesto da raste za nekoliko upita sa svakom novom stranicom,
uz potpuno isto nasljeđivanje i sigurnosne provjere.

I popisi Područja slijede isto pravilo: ACL retci svih prikazanih Područja za
običnog korisnika učitavaju se jednim upitom, dok administratorski brzi put ne
čita ACL retke koji mu ne mogu trebati. Dodavanje Područja zato ne stvara ACL
upit za svaki red popisa.

Glavni popis prikazuje 25 područja po stranici i ima ograničen prozor brojeva
stranica. Ako opcionalni presentation provider označi osobna područja i njihov
stabilni ID vlasnika, administratorski glavni popis ih izdvaja iza gumba
**Osobna područja**. Običnom korisniku vlastito osobno područje ostaje u glavnom
popisu, a tuđa osobna područja koja su već prošla isti ACL filtar pojavljuju se
u zasebnom padajućem izborniku. Oznaka osobnog područja nikada ne zaobilazi ACL.

Rezultati tog izračuna vrijede samo tijekom jednog zahtjeva. Nakon spremanja
Workspace ili čvornog ACL-a controller izričito prazni kratkotrajni cache, pa
sljedeća provjera u istom zahtjevu također vidi nova prava.

## 5. Stablo stranica

Lijevo stablo može se prikazati ili sakriti. Izgledom prati karticu sadržaja
HTML editora: koristi isti naslov, `list-group`, temu i unutarnji scroll. Na
desktopu ostaje dostupno tijekom čitanja. Na mobilnom se iz glavnog toka uklanja
u plutajuću karticu odmaknutu od svih rubova zaslona koja klizi slijeva. Kartica
zadržava boje, obrub, akcent i sjenu stabla iz aktivne teme. Diskretna,
zalijepljena rubna ikona otvara karticu, a gumb zatvaranja, pozadina ili tipka
Escape vraćaju čitatelja na dokument. Fokus i `aria-expanded` stanje prate
otvorenu karticu.

Stablo za čitanje je kompaktna lista bez okvira oko stavki. Grane prve razine
prikazuju drugu razinu, dok dublje grane počinju sažete. Izravni URL označava
otvorenu stranicu i širi samo putanju njezinih predaka; isto pripremljeno stanje
ostaje i kada je cijela kartica stabla početno skrivena pa se naknadno otvori.
Organizator za uređivanje ostaje potpuno proširen kako bi pri slaganju uvijek
bila dostupna cijela hijerarhija.

Kada čitatelj ručno proširi ili sažme grane, to se stanje zadržava tijekom
navigacije unutar istog Područja u trenutačnoj kartici preglednika. Svako
Područje ima neovisno stanje. Preci aktivne stranice uvijek se otvaraju nakon
navigacije pa spremljeni odabir nikada ne može sakriti označenu stranicu.

Kartica stabla i HTML kartica počinju u istom retku. Prekidač stabla nalazi se
kao prva SVG akcija zajedničkog Editorova pregleda, dok su SVG akcije za novu
stranicu i upravljanje Područjem u zaglavlju samog stabla. Tako akcije ne
rezerviraju zaseban prazan red, a njihova vidljivost i dalje prati efektivni ACL.
Kompaktne akcije zaglavlja poravnate su desno u vlastitom retku, a puni naziv
Područja prikazuje se ispod njih bez skraćivanja.

Vrste čvorova:

- `document`: povezuje jedan dokument HTML editora;
- `internal_link`: vodi na imenovanu projektnu rutu ili internu putanju;
- `external_link`: vodi na provjereni vanjski URL.

Roditelj i redoslijed određuju hijerarhiju. Korisnik s potpunim pravom
upravljanja uključuje organizator izravno ikonom u zaglavlju lijevog stabla:
strelice gore/dolje pomiču cijelu podgranu među stavkama istog roditelja, a
strelice lijevo/desno izvlače ili uvlače podgranu za jednu razinu. Nedostupne
radnje su onemogućene. Brojevi redoslijeda sinkroniziraju se automatski i cijeli
se raspored sprema jednom atomskom ORM transakcijom. Repository prije prvog
zapisa provjerava da su poslani svi aktivni čvorovi, da su roditelji
dokument-stranice i da nema ciklusa.

Cijeli organizator, popis dostupnih dokumenata i obrazac za dodavanje učitavaju
se tek pri prvom kliku na ikonu. Običan pregled zato ne šalje veliki skriveni
HTML obrazac, što bitno smanjuje odgovor kod uvezenih područja s mnogo stranica.

Mala edit ikona uz stavku otvara Bootstrap modal s naslovom, slugom, vrstom,
ciljem, nasljednim ograničenjima i brisanjem. Obrazac se učitava na zahtjev pa
velika stabla ne stvaraju stotine skrivenih formi. Gumb na dnu organizatora
otvara modal za dodavanje linka ili postojećeg dokumenta i odabir početnog
roditelja. Brisanje čvora soft-briše cijelu podgranu. Povezani editor dokument
soft-briše se kroz opcionalni servisni most. Zaseban ekran “Upravljaj
područjem” zadržava samo podatke područja, članove, Workspace ACL i brisanje
područja.

Premještanje čvora traži `can_edit` na čvoru i `can_add` na novom roditelju
odnosno korijenu. Upravljački prikaz uopće ne šalje korisniku čvorove za koje
nema efektivno `can_view` pravo. Korisnik s pravom izmjene sadržaja vidi
upravljanje stablom, ali podatke i ACL samog Područja može mijenjati samo uz
`can_manage`.

Interni link prihvaća postojeću imenovanu rutu ili lokalnu apsolutnu putanju
koja počinje jednom kosom crtom, primjerice `/calendars`. Lokalna putanja
automatski se smješta ispod aplikacijskog prefiksa pa postaje `/example-app/calendars`
kada je aplikacija instalirana pod `/example-app`. Apsolutni HTTP(S) URL-ovi dopušteni
su samo tipu `external_link`.

Jedan aktivni HTML dokument može pripadati samo jednom aktivnom Workspace
čvoru. Tako su vlasništvo URL-a i ACL-a nedvosmisleni.

### 5.1 Sažetci Područja

Ikona popisa u zaglavlju svakog vidljivog stabla otvara
`/{korijen-područja}/{slugPodručja}/shorts`. Vidi je svaki korisnik koji smije
vidjeti Područje; to nije upravljačka akcija.

Stranica ima tri neovisna odabira:

- samo 1., razine 1–2 ili razine 1–3 vidljivog stabla;
- 5, 10, 25, 50 ili sve članke;
- hijerarhijski redoslijed, najnovije ili najstarije prvo.

Opcija `all` uključena je samo ispod 100 dopuštenih članaka. Controller na 100
ili više odbija i ručno sastavljen `limit=all` te koristi konfiguriranu
brojčanu zadanu vrijednost. Zadane postavke žive pod ključem `shorts` u
`config/workspace.php`, a uređuju se pod **Postavke → Područja**. Isporučene
zadane vrijednosti su razine 1–2, 10 članaka i najnoviji prvo.

Stablo stranica početno je prikazano, a opcije prikaza početno su skrivene.
Njihovi ikon-gumbi prate temu i uvijek su dostupni s pristupačnim nazivima i
opisima. Izravna poveznica može parametrima `tree=0|1` i `options=0|1`
promijeniti bilo koje stanje; bez parametara koristi se konfiguracija sitea.
Obrazac filtra čuva trenutačna stanja pa ista ruta može biti obična stranica ili
kompaktna naslovnica.

Ako Menu isporučuje poseban lijevi meni za aktivnu rutu, aplikacija početno
sklapa Workspace stablo bez obzira na zadanu postavku područja. Time se izbjegavaju
dva istodobno otvorena lijeva navigacijska elementa, a obje ikon-sklopke ostaju
dostupne; `tree=1` i dalje je izričit odabir za izravne poveznice.

Sigurnosno filtriranje izvršava se prije učitavanja HTML-a: servis kreće od
`visibleTree()`, primjenjuje nasljedni ACL čvorova, zadržava dokument-čvorove
odabrane dubine pa traži nearhivirani workflow s pozitivnim pokazivačem na
objavljenu verziju. Isto pravilo vrijedi vlasniku i administratoru, pa njihovo
pravo pregleda nacrta nikada ne izlaže nacrt u Sažetcima. Editor dobiva samo već
ograničenu mapu dokument/verzija i skupno učitava točno te nepromjenjive
verzije. View renderira Editorom sanitizirani HTML unutar isječka od dvanaest
redaka s fadeom prilagođenim temi i vodi na kanonsku Workspace stranicu. Za
svaku stranicu prvo traži aktivni jezik, a zatim isključivo točno objavljenu
verziju zadanog jezika sitea iz `app.localization.locale`. Nacrt nikada nije
jezični fallback.

Sažetci ne dodaju tablicu baze. Zadane vrijednosti pripadaju konfiguraciji
sitea, pa potpuni backup uključuje `config/workspace.php`; izvoz paketa teme
nije njihov vlasnik.

## 6. Integracija s HTML editorom

Integracija je opcionalna i izvedena servisima. Workspace dinamički prepoznaje
paket editora i koristi njegove javne servise. Editor na isti način prepoznaje
Workspace ACL bez tvrde Composer ovisnosti.

Kada je Workspace uključen:

- postavke editora pokazuju da Područja upravljaju javnim putanjama;
- samostalni editor slug prekidač je isključen;
- povezani dokument učitava se samo uz nasljedno `can_view` pravo;
- Workspace u desnom stupcu ugrađuje Editorov zajednički potpuni pregled, pa su
  tema, jezici, povijest, privitci, ZIP export, sadržaj dokumenta i audit
  identični samostalnom pregledu;
- uređivanje, upload, metapodaci, verzije i privitci traže `can_edit`;
- brisanje dokumenta traži `can_delete`;
- URL-ovi dokumenata u Menu modulu vode na Workspace stranice.

Workspace ne čita Editorove privatne tablice i ne kopira njegov HTML predložak.
Kroz opcionalni servisni most traži `EditorDocumentViewBuilder`, a zatim
renderira Editorov službeni `editor/view` partial uz lijevo stablo. Jezični i
TOC linkovi zato ostaju u trenutačnom Području, dok export i asset rute ponovno
provjeravaju isti nasljedni ACL na serveru.

Tijekom jednog HTTP zahtjeva integracijski servis pamti već pronađeni dokument,
pripadajuće Područje, izračunata prava, javnu putanju i broj objavljene verzije.
Editor pri izgradnji jednog pregleda iste podatke treba za više ikona,
jezičnih poveznica i stavki povijesti. Ovaj kratkotrajni cache uklanja
ponovljene ORM upite, ne prenosi se u sljedeći zahtjev i ne mijenja sigurnosne
provjere.

Korisnik s pravom `can_add` u otvorenom Području vidi naredbu **Nova
stranica**. Sažeti obrazac traži samo naslov, opcionalni slug i nadređenu
stranicu. Nakon spremanja modul kreira editor dokument, povezuje njegov
stabilni ključ sa stranicom stabla i odmah otvara HTML editor. Prva kreirana
stranica automatski postaje početna stranica Područja.

Kreiranje dokumenta prvo provjerava `can_add` na Području i odabranoj
nadređenoj stranici. Link ne može biti roditelj nove stranice.
Obični urednik može zadržati dokument svojeg čvora ili automatski kreirati novi.
Ne može ručnim POST zahtjevom povezati drugi postojeći dokument. Administrator
može odabrati postojeći dokument iz popisa, a server prije spremanja provjerava
da dokument stvarno postoji i da već ne pripada drugom aktivnom čvoru.

Upravljački ekran nije mjesto za pisanje sadržaja ni slaganje stabla. Stablo,
linkovi i nasljedna ograničenja uređuju se u kontekstu otvorenog Područja.
Napredni modal prikazuje samo polja relevantna odabranoj vrsti stavke.

Bez HTML editora Područja i linkovi i dalje rade. Bez Workspace modula HTML
editor zadržava samostalno ponašanje.

### 6.1 Prenosivi HTML izvoz Područja

`GET /workspaces/export?workspace={slug}` otvara obrazac, a
`POST /workspaces/export` vraća ZIP. Obje rute traže prijavu; controller zatim
zahtijeva administratorski status ili efektivno Workspace pravo `can_manage`.
Ta druga autorizacijska provjera obavezna je i kada korisnik zna izravni URL.

Odaberite **Cijelo područje** za sve objavljene stranice koje izvoznik smije
vidjeti ili **Odabrane stranice** i pošaljite `node_ids[]`. Server ne vjeruje tim
ID-evima: ponovno gradi nasljedno ACL stablo za trenutačnog korisnika, uklanja
nacrte i stranice dostupne samo kao arhivirane te presijeca poslanu listu s tim
rezultatom. Stranica sa strožim naslijeđenim ili izravnim ograničenjem zato ne
ulazi ni u generirano stablo ni u ZIP.

Struktura paketa je:

```text
index.html                     # jedina tematizirana offline ljuska
manifest.json                  # izvor, jezici i popis izvezenih stranica
assets/css/                    # Theme, Bootstrap, Editor, Calendar i Task CSS
assets/js/workspace-export.js  # lokalne kontrole teme, jezika i panela
assets/theme/                  # samo aktivne light/dark datoteke teme
documents/{ključ-dokumenta}/{izvorni-jezik}/v{verzija}/
                               # privitci nepromjenjive verzije bez sudara jezika
{objavljeni-jezik}/{stranica}.html
```

Nakon raspakiravanja korijenski `index.html` otvara se izravno iz filesistema.
To je jedina tematizirana offline aplikacijska ljuska. Jedina stavka menija je
**Početna** i vraća izvezenu početnu stranicu Područja. Linkovi stabla mijenjaju
ugrađenu snimku nepromjenjive stranice bez HTTP-a i `fetch` poziva. Ljuska
ponovno koristi stvarne renderere zaglavlja i heroa Theme modula, prenosivi CSS,
odabrani svijetli/tamni logo i hero vizual, širinu containera te klase
preklapanja sadržaja. Hero zato prikazuje naslov trenutačne stranice jednako kao
aplikacija i dodaje lokaliziranu napomenu `Izvezeno područje: {Područje}`. Ne
dodaje drugi naslov stranice.

Odabrane light/dark slike loga i hero vizuala ugrađene su u `index.html` kao
data URI. Time se njihov prikaz ne oslanja na relativnu putanju preglednika pri
otvaranju kroz `file://`. Izvorne odabrane datoteke ostaju pod `assets/theme/`
radi pregleda i provjere cjelovitosti ZIP-a.

Svaka datoteka `{objavljeni-jezik}/{stranica}.html` čisti je samostalni dokument
izrađen Editorovim formatterom. Izravno otvaranje prikazuje isti renderirani HTML
kao izvoz jedne stranice, bez Workspace zaglavlja, heroa, stabla i akcija
uređivanja. Samostalna datoteka ne učitava Theme CSS i zato nema pozadinu
aplikacije. Jezični direktoriji i datoteke postoje samo za stvarne objavljene
prijevode. Korijenski selector svejedno nudi sve konfigurirane jezike; kada
prijevod ne postoji, prikazuje zadanu hrvatsku snimku, a zatim prvu stvarnu
snimku. Taj runtime fallback nikada ne stvara dupliciranu jezičnu datoteku.

Dinamički uključene stranice prerenderiraju se s aktualnim objavljenim
sadržajem, a njihove potrebne slike i privitci prenose se u paket uz ponovnu
ACL provjeru. Calendar i Task placeholderi pretvaraju se u statični read-only HTML kroz iste
ACL-aware integracijske metode kao Editorov izvoz jedne stranice. Nativni
Editor grafikoni pretvaraju se u responzivni samostalni inline SVG te
zadržavaju zadanu širinu, poravnanje, razmak, legendu i boje teme. Izostavljaju
se akcije uređivanja i preuzimanja kalendara: kalendar je renderirani read-only
snapshot. Izvezena stranica nema aktivne API zahtjeve. Privitci dolaze iz točne
objavljene verzije, nikada iz nacrta koji se uređuje. Kontrole teme, jezika,
stabla, sadržaja dokumenta i privitaka rade lokalno bez Bootstrap JavaScripta.
Kada Theme modul nije instaliran ili je isključen, ista struktura paketa ostaje
funkcionalna uz minimalno čitljivo fallback zaglavlje, hero i raspored.

## 7. Proces objave

Stanje objave pripada dokument-čvoru i jeziku. Workspace sprema samo status,
audit vremena, korisničke identifikatore i brojeve Editorovih verzija. HTML
sadržaj, privitci i nepromjenjive verzije i dalje pripadaju HTML editoru.

Čisto početno stanje je **Nacrt**. Dokument-čvor bez workflow retka također se
tretira kao neobjavljeni nacrt; nema starog automatskog objavljivanja.
Podržana stanja su:

1. **Nacrt**: urednici rade na jednom zajedničkom promjenjivom nacrtu.
   Obični pregled svakome pokazuje ranije objavljenu verziju ako ona postoji.
   Nacrt se otvara zasebnim akcijama za uređivanje ili pregled. Obični pregled
   objavljene stranice ne nudi akcije koje mijenjaju nacrt; odbacivanje, slanje
   na pregled i objava dostupni su tek na eksplicitnom pregledu nacrta.
2. **Na pregledu**: radna je verzija spremna za pregled, ali još nije javna.
3. **Objavljeno**: odabrana nepromjenjiva verzija postaje verzija za čitatelje.
4. **Arhivirano**: stranica se uklanja iz stabla i pregleda za čitatelje.
   Vraćanjem nastaje neobjavljeni nacrt koji treba ponovno objaviti.

Spremanje mijenja isti zajednički nacrt i ne dodaje ga u povijest. Povijest
sadrži samo objavljene, nepromjenjive verzije. Vraćanje povijesne verzije,
kopiranje jezika, brisanje privitka ili druga promjena sadržaja također priprema
zajednički nacrt. Time se ne zamjenjuje `published_version_number`, pa svaki
obični pregled dobiva stabilan objavljeni sadržaj dok se priprema sljedeća objava.

Prava namjerno odvajaju uređivanje od objavljivanja:

- `can_edit`: slanje nacrta na pregled i povratak s pregleda;
- `can_publish`: objava nacrta, uključujući izravno `Spremi i objavi`, nakon
  čega se otvara javni pregled objavljene stranice;
- `can_manage`: uključuje sva prava te dodatno arhiviranje i vraćanje;
- svi korisnici na običnom pregledu vide točno zapisanu objavljenu verziju i
  njezin povijesni skup privitaka;
- korisnici s pravom uređivanja ili objavljivanja dobivaju zasebne ikone za
  uređivanje i pregled zajedničkog nacrta.

Prijelazi se provjeravaju na serveru kroz `POST /workspaces/workflow`; izmjena
gumba, URL-a ili request tijela ne može zaobići efektivni nasljedni ACL.
Diskretne workflow akcije prikazuju se samo korisniku koji ih smije izvršiti.
Nove nikad objavljene stranice označene su uz naslov u stablu i dostupne kroz
brojač `Nove neobjavljene stranice`. Korisnici s pravom `can_publish` imaju i
zaseban brojač `Poslano na pregled` s popisom stranica spremnih za objavu.
Odbacivanje nove stranice bez ijedne objave na bilo kojem jeziku trajno briše
njezin Workspace čvor, workflow, ograničenja i Editor dokument s privitcima.
Stranica zato ne završava među soft-obrisanim dokumentima. Ako čvor ima djecu,
ona se premještaju njegovu roditelju. Ova destruktivna inačica odbacivanja traži
efektivno `can_delete` pravo. Ako postoji objava na drugom jeziku, odbacuje se
samo nacrt trenutačnog jezika.

Kada je instaliran opcionalni Notification modul, slanje nacrta na pregled
kreira dedupliciranu inbox poruku svakom efektivnom objavljivaču osim korisniku
koji je izvršio radnju. Objavljivanje šalje poruku korisniku koji je nacrt
poslao kada to nije ista osoba. Obavijesti su pomoćni kanal i njihov neuspjeh
ne može poništiti uspješan workflow prijelaz. Ako je uključen opcionalni E-mail
modul, ista obavijest može se staviti i u njegov trajni SMTP outbox.

Nakon promjene pokazivača objave, onemogućavanja stranice ili podstabla te
promjene metapodataka Područja ili stabla repozitorij šalje neutralni događaj
`WorkspaceContentChanged`. Opcionalni izvedeni moduli mogu ga slušati i
sinkronizirati samo pogođeno Područje i jezik. Spremanje nacrta koje zadržava
trenutačni pokazivač objave namjerno ne šalje promjenu objave, pa čitatelji i
indeksi nastavljaju koristiti zadnju objavljenu verziju. Neuspjeh slušatelja
odvojen je od spremanja izvornog sadržaja; periodični ili ručni reindeks može
popraviti izvedeno spremište bez gubitka mjerodavnih Workspace podataka.

Kada Workspace nije instaliran, svi integracijski pozivi su no-op. Samostalno
spremanje, pregled, povijest i export Editora nastavljaju koristiti aktualnu
verziju dokumenta kao i prije.

## 8. Konfiguracija

`config/workspace.php` podržava:

```php
return [
    'enabled' => true,
    'routing' => [
        'root_path' => 'workspace',
    ],
    'defaults' => [
        'visibility' => 'restricted',
        'tree_visible' => true,
        'contents_visible' => false,
    ],
    'creation' => [
        'users' => [],
        'groups' => [],
    ],
    'menu' => [
        'auto_register_top' => true,
        'auto_register_settings' => true,
    ],
];
```

Administratori uvijek smiju kreirati Područja. Ostali se kreatori odabiru u
Postavkama područja kao pojedinačni Auth korisnici ili postojeće Auth grupe.

`tree_visible` i `contents_visible` su sistemske rezervne vrijednosti. Svako
područje može za stablo i sadržaj odabrati nasljeđivanje, prikaz ili skrivanje,
a pojedina stranica može dodatno nadjačati samo prikaz svojeg sadržaja. Redoslijed
razrješavanja je: **stranica → područje → sistemska postavka**. URL parametri
prikaza ostaju jednokratna korisnička iznimka i ne mijenjaju spremljene zadane
vrijednosti.

Korijenska putanja mora biti slobodan prvi segment rute. Postavke odbijaju
konflikt s postojećom rutom aplikacije.

Ako je Menu uključen, Workspace idempotentno registrira:

- glavnu stavku Područja;
- Opće postavke;
- Sva područja;
- Obrisana područja.
- dinamička odredišta editora za svako aktivno područje i dokumentnu stranicu.

Ponovljeni requesti ne dupliciraju niti premještaju te stavke.
Administratorske stranice Područja prikazuju zajednički lijevi izbornik
Postavki kada je Menu dostupan. Bez Menu modula iste stranice ostaju uporabive
kroz lokalni rezervni izbornik s Općim postavkama, Svim područjima i Obrisanim
područjima.

Integracija odredišta je lijena, pa otvaranje bilo kojeg od četiri Menu editora
čita trenutačno stablo područja. Područja su u grupi **Područja**, a stranice u
grupi **Stranice područja** kao `Područje / Stranica`. Menu ih u izborniku
**Primijeni poseban meni na** spaja sa svim ostalim navigacijskim stranicama.
Odabir područja popunjava `/workspace/slug` i `/workspace/slug/*`; putanje nikad
ne sadrže trenutačni instalacijski base path. API, akcijske, dijaloške, asset i
ostale tehničke rute filtrira Menu i nikad ne postaju navigacijski izbori.

### Pravila teme po Području

Theme je opcionalna integracija. Kada je uključen, upravitelj može ostaviti
**Zadanu sistemsku temu**, izričito odabrati jednu sistemsku temu ili
urediti/uvesti izoliranu privatnu temu. Prva izmjena stvara potpunu privatnu
kopiju i nepromijenjenim nazivima dodaje naziv Područja. Workspace zahtjev
nikada ne zapisuje sistemski Theme JSON.

Redak `workspace_themes` sadrži vrstu izbora, ID izvora, mode policy i privatni
JSON teme. Privatni binarni/SVG asseti nalaze se u
`data/workspaces/themes/<workspace-id>/assets`, a reference imaju oblik
`@runtime-theme-assets/<datoteka>`. Posluživanje asseta ponovno provjerava pravo
pregleda, dok Theme repozitorij prima razriješeni override samo tijekom
trenutačnog zahtjeva. Time globalna aktivna tema ostaje nepromijenjena na svim
stranicama izvan Područja i u drugim Područjima.

Upravitelji mogu uvesti potpuni Theme v3 paket. Izvoz je namjerno dostupan samo
administratoru aplikacije i samo za privatnu temu Područja. Paket je kompatibilan
i s uvozom u drugo Područje i s uvozom u globalnu Theme biblioteku. Potpuni
backup mora obuhvatiti tablicu `workspace_themes` i direktorij
`data/workspaces/themes`.

### Posebni meniji po Području

Menu je još jedna opcionalna integracija. Korisnik s efektivnim pravom
`can_manage` može iz **Upravljaj područjem** otvoriti **Posebne menije
područja** i neovisno uređivati gornji i lijevi route-specific meni tog
Područja. Kontroler ponavlja Workspace ACL provjeru i za GET i za POST zahtjev.

Preglednik nikada ne određuje opseg. Server prije spremanja prepisuje ID
contexta, naziv, route patterne i prenosive path patterne vrijednostima
`/workspace/{slug}` i `/workspace/{slug}/*` odabranog Područja. Krivotvoreni
zahtjev zato ne može promijeniti meni izvan Područja. Definicija samo lijevog
menija nije vidljiva u editoru gornjeg menija i obratno; uklanjanje jedne strane
čuva drugu. Runtime ipak smije spojiti podudarne odvojene zapise kako bi se oba
menija prikazala na istoj stranici.

### Navigacijska putanja i povratne poveznice

Svaka prikazana stranica ima navigacijsku putanju **Početna → Područje →
vidljivi preci → stranica**. Servis prima stablo nakon ACL filtriranja, stoga
ne može otkriti ime skrivenog pretka. Aktivna stranica nije poveznica, a njezin
naslov koristi lokalizirani naslov točno prikazane Editor verzije.

Blok **Poveznice na ovu stranicu** navodi druge objavljene stranice koje vode
na trenutačnu stranicu. Pri svakom prikazu ponovno se provjeravaju Workspace i
stranični ACL te objavljeno stanje izvorne stranice. Gost zato vidi samo izvore
koje smije javno otvoriti. Aktivni jezik ima prednost; ako na njemu nema zapisa
izvorne stranice, koristi se zadani jezik sitea.

Ispod njega blok **Stranica je uključena u sadržaj sljedećih stranica** navodi
objavljene, trenutačno čitljive stranice koje cilj koriste kroz Editorovu
dinamičku funkcionalnost **Uključi sadržaj stranice**. Popis se gradi iz trajnih
Editor referenci, ali ponovno provjerava aktualnu verziju, Workspace workflow i
ACL izvora kako uklonjena referenca ili naknadno ograničena stranica ne bi bila
otkrivena.

Navigacijska putanja nalazi se iznad cijelog rasporeda pa stablo, poseban lijevi
meni, sadržaj stranice i tablica sadržaja ostaju u istoj ravnini. Preuzima
kontrastne boje teksta hero elementa, a tema ih po potrebi može zasebno
nadjačati kroz `--hph-workspace-breadcrumb-text` i
`--hph-workspace-breadcrumb-link`, dok se prilagodljiva veličina teksta može
nadjačati kroz `--hph-workspace-breadcrumb-font-size`. Na desktopu se duga
hijerarhija skraćuje unutar širine sadržaja kako ne bi prekrivala kartice; puni
nazivi ostaju dostupni prelaskom pokazivača. Povratne poveznice koriste iste kartične,
rubne, link i prigušene vrijednosti kao ostale tematske kartice. Bez Theme
modula obje komponente koriste Bootstrap vrijednosti.

Postojeća instalacija dodaje dvije izvedene tablice naredbama:

```bash
vendor/bin/hph workspace:install-backlinks-migration
vendor/bin/hph orm-migrate:up
```

Tablice `workspace_backlinks` i `workspace_backlink_index_state` ne ulaze u
backup jer ne sadrže izvorne podatke. Objavljivanje ciljano osvježava izvor,
strukturna promjena obnavlja indeks, a periodična sigurnosna provjera popravlja
eventualno prekinut događaj.

## 9. Instalacija i rad

```bash
composer require aaieduhr/simbioza-module-workspace
vendor/bin/hph workspace:install-migration
vendor/bin/hph orm-migrate:up
```

Auth i ORM moraju biti uključeni prije Workspace paketa. Modul odgađa
učitavanje dok obavezni servisi nisu dostupni.

Postojeća instalacija dodaje oznake stranica naredbama:

```bash
vendor/bin/hph workspace:install-node-labels-migration
vendor/bin/hph orm-migrate:up
```

Korisne putanje sa zadanom konfiguracijom:

- `/workspaces`: popis vidljivih Područja
- `/workspaces/manage`: kreiranje ili upravljanje Područjem
- `/workspaces/theme?workspace={slug}`: izbor ili privatno uređivanje teme Područja
- `GET /workspaces/acl/subjects`: ograničena serverska pretraga korisnika,
  grupa i ugrađenih publika; zahtijeva pravo upravljanja Područjem
- `GET /workspaces/node/dialog`: ACL-om zaštićen sadržaj modala odabrane stavke
- `POST /workspaces/page/create`: sigurno kreiranje stranice iz otvorenog Područja
- `POST /workspaces/tree/order`: atomsko spremanje vizualnog rasporeda stabla
- `POST /workspaces/workflow`: ACL-om zaštićen prijelaz procesa objave
- `/workspace/{područje}`: početna stranica Područja
- `/workspace/{područje}/{stranica}`: stranica ili link čvor
- `/settings/workspaces`: administratorske postavke
- `/settings/workspaces/homepage`: javna, prijavljena i osobna politika naslovnice
- `/settings/workspaces/all`: administratorski popis
- `/settings/workspaces/deleted`: vraćanje obrisanih Područja

Postojeća instalacija dodaje izolirane teme Područja naredbama:

```bash
vendor/bin/hph workspace:install-themes-migration
vendor/bin/hph orm-migrate:up
```

### Politika naslovnice aplikacije

Workspace posjeduje ovu funkcionalnost jer je svaki spremljeni cilj Workspace
stranica koju treba ponovno provjeriti kroz nasljedni ACL i proces objave. Auth
i dalje radi potpuno samostalno: Workspace registrira vlastiti opcionalni
partial korisničkog profila i automatski ga uklanja kada modul nije uključen.

Administrator u grupi postavki Područja bira objavljenu stranicu ili prikaz
Sažetaka kao javni i prijavljeni zadani cilj. Cilj Sažetaka ima strukturirane
prekidače **Vidljivo stablo stranica** i **Vidljive opcije prikaza**. Korisnik
kojem je dopušten osobni izbor vidi samo objavljene stranice i Sažetke kojima
trenutačno smije pristupiti. Resolver početne rute primjenjuje osobna →
prijavljena → javna → host naslovnica i preusmjerava samo na generiranu internu
named rutu, čime sprječava otvoreni redirect i pristup kroz zastarjeli ACL.

Postojeća instalacija dodaje dvije prijenosne tablice naredbama:

```bash
vendor/bin/hph workspace:install-homepage-migration
vendor/bin/hph orm-migrate:up
```

Instalacija koja već ima te dvije tablice dodaje strukturirane ciljeve Sažetaka
bez zamjene postojećih izbora stranica:

```bash
vendor/bin/hph workspace:install-homepage-view-options-migration
vendor/bin/hph orm-migrate:up
```

Potpuni backup sitea obuhvaća `workspace_homepage_settings` i
`workspace_user_homepages`, uključujući vrstu cilja, ID Područja i oba
prekidača vidljivosti. Uvoz pojedinačnog Područja ne smije neprimjetno
zamijeniti politiku naslovnice odredišnog sitea, a izvoz teme ne posjeduje ove
vrijednosti.

## 10. API integracija

### Oznake stranica

Workspace može čuvati prenosive izvorne oznake koje daju pouzdani importi i
integracije. Selektivni backup područja prenosi oznake prema UUID-u stranice,
pa ih `copy`, `merge` i `replace` povrat ne gube. Oznake same po sebi ne
stvaraju predložak stranice ni dinamičku komponentu korisničkog sučelja.

Workspace se i dalje može instalirati samostalno. Transportno neutralni
`WorkspaceApiService` sadrži poslovnu granicu. Kada je opcionalni API uključen,
Workspaceov `WorkspaceApiExtension` registrira rute, a
`WorkspaceResourceController` prilagođava taj servis:

| Metoda i putanja | Potrebni scope | Domensko pravilo |
| --- | --- | --- |
| `GET /api/v1/workspaces` | `workspace:read` | Vraća samo vidljiva područja |
| `POST /api/v1/workspaces` | `workspace:manage` | Koristi aplikacijsko pravilo kreiranja |
| `GET/PATCH/DELETE /api/v1/workspaces/{slug}` | read/manage | Ponovno provjerava efektivno pravo |
| `GET /api/v1/workspaces/{slug}/tree?lang=hr` | `workspace:read` | Filtrira naslijeđeni ACL i objavljeno stanje |
| `GET /api/v1/workspaces/{slug}/shorts?lang=hr` | `workspace:read` | Vraća samo vidljive, točno objavljene sažetke |
| `POST /api/v1/workspaces/{slug}/exports/html` | `workspace:manage` | Preuzima ACL-filtrirani offline HTML ZIP |
| `GET/PUT /api/v1/workspaces/homepage/settings` | `workspace:manage` | Samo administrator čita ili sprema javnu/prijavljenu politiku |
| `GET/PUT /api/v1/workspaces/homepage/preference` | `workspace:read` | Čita ili sprema osobni odabir isključivo vlasnika ključa |
| `GET/PATCH /api/v1/workspaces/{slug}/theme` | `workspace:manage` | Čita ili privatno mijenja temu bez promjene sistemske teme |
| `PUT .../{slug}/theme/selection` | `workspace:manage` | Bira nasljeđivanje ili sistemsku temu samo za područje |
| `POST .../{slug}/theme/import` | `workspace:manage` | Uvozi ZIP u privatnu temu područja |
| `GET .../{slug}/theme/export` | `workspace:manage` | Samo administrator izvozi privatnu temu |
| `POST/DELETE .../{slug}/theme/assets` | `workspace:manage` | Dodaje ili briše datoteke privatne teme |
| `PUT /api/v1/workspaces/{slug}/tree/order` | `workspace:manage` | Atomski provjerava potpuni raspored |
| `GET/PUT /api/v1/workspaces/{slug}/acl` | `workspace:manage` | Čita ili zamjenjuje potpuni ACL područja |
| `GET /api/v1/workspaces/{slug}/acl/subjects` | `workspace:manage` | Ograničena pretraga `category=user\|group&q=...` |
| `POST/PATCH/DELETE .../nodes` | `workspace:manage` | Upravlja samo internim i vanjskim linkovima |
| `GET/PUT .../nodes/{nodeId}/acl` | `workspace:manage` | Čita ili mijenja izravna ograničenja |
| `GET /api/v1/workspaces/deleted` | `workspace:manage` | Samo administrator |
| `POST /api/v1/workspaces/deleted/{id}/restore` | `workspace:manage` | Samo administrator, rješava sukob sluga |

Scope ključa je prva zaštita, a nikada konačna odluka. Servis zatim računa ista
prava vlasnika, administratora, Workspace ACL-a, grupa, arhive i naslijeđenih
ograničenja koja koristi web sučelje. Nedostatak prava pregleda skriva se kao
`404`, a vidljiv resurs bez prava upravljanja vraća `403`.

DTO područja sadrži `tree_visibility` i `contents_visibility`, a DTO čvora
sadrži `contents_visibility`, `labels` i `properties`. `PATCH` čvora prihvaća
JSON polje `labels` te polje `properties` s objektima `key`, `label`, `type`,
`value` i `sort_order`, uz pravo upravljanja područjem. Podržani tipovi
svojstava su `text`, `status` i `link`. Prihvaćene vrijednosti prikaza su
`inherit`, `shown` i `hidden`. HTML izvoz prihvaća opcionalnu JSON listu `node_ids`. Prazna lista
izvozi cijelo vidljivo područje, a odabrani ID-evi izvoze samo te stranice i
stablo potrebno za dolazak do njih.

Pretraga ACL subjekata vraća samo sigurni picker DTO: `id`, `label`, `type`,
`category`, `is_builtin` i `is_read_only`. Interna Auth polja, uključujući
hash lozinke i podatke prijave, ne prelaze granicu repozitorija.

Tema područja je izolirana: `PATCH .../theme` uvijek stvara ili mijenja privatnu
kopiju u bazi i podatkovnom direktoriju područja. Sistemski JSON nikada se ne
zapisuje kroz Workspace API. Uvoz koristi multipart polje `theme`, a upload
slike polje `asset` uz `role=hero|icon|logo|other`.

Primjer čitanja ključem koji ima `workspace:read`:

```bash
curl --fail-with-body --silent --show-error \
  --header "Authorization: Bearer $HPH_API_TOKEN" \
  --header 'Accept: application/json' \
  "$HPH_API_URL/workspaces"
```

JSON envelope sadrži samo vidljive Workspace DTO objekte u `data`,
`meta.request_id` i navigacijski `links`. Dvojezični brzi početak API modula
sadrži ekvivalentni obični PHP klijent i očekivani problem odgovor.

Početnička mentalna slika: `workspace:manage` znači „klijent smije zatražiti
upravljanje područjem”. Korisnik kojem ključ pripada i dalje mora imati pravo
izvršiti konkretnu operaciju.

Dokument-stranice i privici namjerno nisu dio ovog ugovora. HTML Editor API
posjedovat će `page:*` i `attachment:*`, a Workspace integracija će dodati
efektivni ACL stranice kada su oba modula instalirana.

## 11. Razvojne provjere

```bash
composer on-commit
```

Naredba pokreće PHPCS, Rector dry-run, PHPStan za izvorni i testni kod te
PHPUnit. Svaka metoda dokumentirana je na hrvatskom i engleskom. Prikazi
escapeaju ispis, forme koriste framework CSRF polje, a kontroleri prije
pisanja provjeravaju pripadnost Području.

## 12. Backup i povrat

Selektivna arhiva područja, ovlasti upravitelja, načini obrade konflikata i veze
između modula opisani su u [backupu i povratu područja](backup_hr.md).

## 13. Održavanje prostora

Administratorska stranica **Postavke → Područja → Održavanje** prikazuje
procijenjeni prostor baze i stvarnu veličinu upravljanih datoteka za cijeli site
i svako aktivno područje. Odvojeno su prikazani povijesne verzije te obrisane
stranice i privitci, pa administrator prije čišćenja vidi gdje nastaje višak.

Čišćenje se može ograničiti na cijeli site ili jedno područje. Za povijest je
moguće:

- ne mijenjati povijest;
- obrisati sve stare verzije;
- zadržati zadnje 3, 5 ili 10 verzija po dokumentu i jeziku;
- obrisati verzije starije od 10, 30 ili 90 dana.

Obrisane stranice i privitci mogu se trajno ukloniti nakon 10, 30 ili 90 dana.
Dok se to ne napravi, riječ je o mekom brisanju: administrator ih još može
vratiti. Trajno čišćenje je nepovratno, zato prije njega treba napraviti
provjeren backup.

Servis uvijek štiti trenutačnu verziju, najnoviju verziju svakog jezika te
verzije koje stablo područja označava kao trenutačne ili objavljene. Privitak se
ne briše dok ga koristi bilo koja sačuvana verzija. Baza se mijenja u
transakciji, a datoteke se uklanjaju tek nakon uspješnog upisa promjena.

Prikaz veličine baze je procjena korisnog sadržaja redaka, a ne veličina cijele
datoteke baze. Nakon brisanja sustav baze oslobođene stranice uobičajeno ponovno
koristi, pa fizička datoteka ne mora odmah postati manja. `VACUUM`, `OPTIMIZE`
ili slična administratorska operacija ovisi o SQLiteu, PostgreSQL-u ili MySQL-u
i zato se ne pokreće automatski iz prijenosnog Workspace modula.

Na istoj stranici administrator može pokrenuti **Optimiziraj postojeće slike**.
HTML Editor tada izrađuje nedostajuće, smanjene WebP kopije slika za prikaz na
webu. Izvorne datoteke ne mijenjaju se i ostaju dostupne klikom na prikazanu
sliku. Isti se postupak automatski primjenjuje pri objavi dokumenta i nakon
Confluence uvoza, dok ručna radnja pokriva sadržaj koji je postojao prije
uključivanja optimizacije ili čija je izvedena kopija uklonjena.
Obrada se izvodi u malim serijama uz vidljivi progress bar. Stanje se trajno
sprema, pa zatvaranje stranice ne gubi već obavljeni rad, a ponovni dolazak na
Održavanje automatski nastavlja nedovršeni posao. Zaključavanje sprječava da dva
otvorena prozora istodobno obrađuju istu seriju.

Soft-obrisano područje administrator može vratiti ili trajno izbrisati na
stranicama **Obrisana područja** i **Održavanje**. Trajno brisanje zahtijeva
ponovni unos točnog sluga. Uklanjaju se Workspace stablo, ACL, workflowi,
privatna tema i njezine datoteke, posebni meniji, Editor dokumenti, povijest i
privitci te povezani podaci instaliranih modula. Izvedeni indeks pretrage briše
se automatski. Samostalni kalendari ostaju sačuvani jer isti kalendar može biti
ugrađen u više područja.

Prije trajnog uklanjanja jedne ili više stranica Workspace šalje javni događaj
`WorkspacePagesPermanentlyDeleting` s ID-ovima čvorova i ključevima dokumenata.
Dodatni moduli tako uklanjaju samo vlastite veze na te stranice. Soft delete ne
šalje ovaj događaj jer se sadržaj još može vratiti.

## Događaj osobnog praćenja

Repozitorij objavljuje `WorkspaceContentChanged` s neosjetljivim razlogom i
identifikatorom izvršitelja. Simbioza User ga može obraditi i prikazati vlastite
kontrole stranice ili područja. Isključivanje tog opcionalnog modula ne mijenja
Workspace rute, pohranu ni ACL ponašanje.

## 14. Oznake, svojstva i dinamički sadržaj

Stranica može imati više oznaka te uređena svojstva `ključ`, `naziv`, `vrsta`,
`vrijednost` i `redoslijed`. Vrste `tekst`, `status` i `poveznica` ostaju
strukturirane u bazi, API-ju i backupu. Status se na pregledu prikazuje kao
tematska oznaka, dok je vrijednost poveznice sigurna samo kada koristi dopušteni
URL. Uvoz Confluence Page Properties podataka puni ista nativna svojstva; ona
nisu posebna polja importera.

HTML Editor na Workspace stranici može umetnuti četiri nativna bloka:

- **Tablica stranica i svojstava** filtrira objavljene ACL-vidljive stranice po oznaci,
  prikazuje odabrana svojstva i može ih sortirati po naslovu, vremenu izmjene
  ili vrijednosti svojstva;
- **Galerija privitaka** prikazuje slike koje pripadaju aktualnoj stranici;
- **Pretraga područja** dinamično predlaže samo rezultate iz trenutačnog
  područja koje posjetitelj smije otvoriti;
- **Nedavne promjene** prikazuje objavljene promjene trenutačnog područja s
  autorom i lokaliziranim vremenom.

Rezultat tablice stranica i svojstava koristi isti obrubljeni, prugasti,
responzivni HTML s hover stanjem kao HTML Editor. Zato nasljeđuje boje
zaglavlja, redaka, alternativnih redaka, obruba i teksta iz aktivne teme, a bez
Theme modula koristi čitljivi Bootstrap fallback.

Blokovi su deklarativni HTML zapisi, ali se njihov rezultat gradi pri svakom
pregledu uz aktualni Workspace i page ACL. Web API vraća isti renderirani HTML,
a HTML izvoz prerenderira read-only rezultat. Backup čuva konfiguraciju bloka u
verziji dokumenta te zasebno čuva oznake i svojstva, pa restore ne gubi
dinamičko ponašanje.

Stranica koja nema nativni dinamički blok ne pokreće njegove upite. Izvještaj
filtriran oznakom skupno provjerava ACL i workflow samo stranica s tom oznakom,
a preklapajući blokovi tijekom istog HTTP zahtjeva dijele već provjerene
rezultate. Izvještaj bez oznake i blok nedavnih promjena namjerno obrađuju cijelo
područje jer njihov rezultat semantički obuhvaća sve njegove stranice.

## 15. Višejezični nazivi područja i stranica

Kada site ima više podržanih jezika, **naziv i opis područja** te **naslov
stranice** uređuju se kroz izbornik jezika uz polje. Vrijednost na primarnom
jeziku sitea je obvezna. Prijevod na ostalim jezicima nije obvezan; ako nije
upisan, prikazuje se vrijednost primarnog jezika. Slug ostaje jedna zajednička,
stabilna vrijednost neovisna o jeziku i zato se poveznice ne mijenjaju pri
promjeni jezika.

Isti višejezični naslov koristi se u obrascu za novu stranicu, postavkama stavke
stabla, HTML editoru, stablu stranica, navigacijskoj putanji, popisima i
rezultatima pretrage. Padajući popisi za izradu menija i povezivanje sadržaja
prikazuju naziv na aktivnom jeziku, uz isti fallback na primarni jezik.

API i obavijesti vraćaju lokalizirani naziv za aktivni jezik zahtjeva. Backup i
povrat čuvaju cijele mape prijevoda područja i stranica. Prenosivi HTML izvoz
renderira odabrani jezik, a Confluence import sprema naziv područja, opis i
naslove stranica pod jezikom odabranim u preflightu. Ponovni import i dalje
povezuje sadržaj stabilnim slugom, a ne prevedenim naslovom.
