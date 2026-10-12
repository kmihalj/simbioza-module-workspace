# Konfiguracija Workspace modula

[Engleska verzija](configuration_en.md)

Aktivne globalne postavke su JSON podatci u `HPH_CONFIG_PATH/workspace/workspace.json`.
Nepromijenjeni resolver frameworka čita i `HPH_CONFIG_OVERRIDE_PATHS` zadanim
redoslijedom. Zadani osnovni direktorij je aplikacijski `config/`, ali naziv
direktorija nije obavezan. Paketni `config/workspace.php` sadrži samo zadane
vrijednosti i administratorski GUI ga nikada ne mijenja.

`config/workspace.json.dist` služi kao polazna točka, ne kao zamjena postojećih
privatnih postavki. Prvo pripremite deklarirane direktorije i prava čitanja/
prolaska. Čitanje ne stvara datoteke ni lockove. Bez JSON-a koriste se zadane
vrijednosti; nečitljiv ili nevaljan JSON izričito se odbija bez tihog učitavanja
starih PHP postavki ili postavki iz baze.

Dvojezični `_documentation` u primjeru opisuje opcije i sigurnu pripremu.
To su inertni metapodatci, ne radna opcija, i mogu se ukloniti. Primjer se nikada
automatski ne kopira u privatnu instalaciju.

## Opcije

| Sekcija | Značenje |
| --- | --- |
| `routing.root_path` | Jedan URL segment malih slova/brojeva odvojenih crticama |
| `defaults` | Vidljivost novog Područja (`restricted`, `authenticated`, `public`) te zadani prikaz stabla i sadržaja |
| `creation.users`, `creation.groups` | Liste pozitivnih Auth ID-ova koji smiju kreirati Područja; administratori uvijek smiju |
| `shorts` | `depth`: 1, 2, 3 ili `"all"`; `limit`: 5, 10, 25, 50; redoslijed: `hierarchy`, `newest`, `oldest`, `title_asc`, `title_desc`; prikaz opcija |
| `backlinks.refresh_seconds` | Sigurnosno osvježavanje izvedenog indeksa, cijeli broj 60–86400 |
| `menu` | Opcionalna registracija glavnog/administratorskog izbornika |
| `homepage` | Javni/prijavljeni cilj i dopuštenje osobnog odabira |

Poznate vrijednosti koriste opisane JSON tipove: boolean, ne `"true"`, i cijeli
ID, ne tekst. Nepoznate dodatne opcije ostaju sačuvane pri spremanju GUI-ja.
Objekti se spajaju kroz slojeve; liste potpuno zamjenjuju niže liste, uključujući
praznu listu kreatora. GUI/API pišu samo u posljednji deklarirani sloj, ponovno
čitaju aktualno stanje pod `workspace.json.lock` i atomski zamjenjuju JSON.
Čuvaju se postojeći mode/vlasnik/grupa; novi direktoriji/datoteke koriste
`0770`/`0660`. Deklarirani korijen smije biti symlink, ali poveznice direktorija
modula, datoteke i locka odbijaju se. Konfiguracija samo za čitanje dopušta
normalan rad; spremanje javlja grešku umjesto pisanja u slabiji sloj.

Postojeće administratorske rute, izbornici i ponašanje GUI-ja ostaju isti.
Opći obrazac i obrazac naslovnice uređuju odvojene dijelove istog JSON-a uz
očuvanje aktualnih međusobnih i ručno podešenih postavki.

## Naslovnice i podatci

Cilj sadrži `type`, `node_id`, `workspace_id`, `show_tree` i
`show_display_options`. Za nasljeđivanje koristite `type="default"` i oba ID-a
nula; za stranicu `type="page"`, pozitivan ID čvora i ID Područja nula; za
Sažetke `type="shorts"`, pozitivan ID Područja i ID čvora nula. Proizvoljni URL
nije dopušten. Resolver pri svakom zahtjevu provjerava objavu i aktualni ACL.

Globalna politika postoji samo u JSON-u. Osobni izbori ostaju u
`workspace_user_homepages`. Područja, stabla, ACL, tijek objave, metapodatci,
privatne teme Područja i njihove datoteke ostaju domenski podatci na dosadašnjim
lokacijama u bazi/`data`. `HPH_CONFIG_PATH` ne seli te datoteke.

## Integracija HTML Editora

Workspace nikada ne zapisuje nametnute vrijednosti u konfiguraciju HTML Editora.
Editor samostalno radi bez Workspacea. Kada je Workspace instaliran i uključen,
postojeća integracija pri izvršavanju koristi politiku sadržaja Područja/stranice,
kanonske Workspace rute, pravila objave/ACL-a i kreiranje vlasničkih stranica.
Samostalne postavke Editora ostaju netaknute i ponovno se primjenjuju kada se
Workspace isključi. Integracija ne kopira vrijednosti između JSON datoteka niti
čini Editor ovisnim o Workspaceu za čitanje vlastite konfiguracije.

## Početna shema i backup

Početna migracija stvara potpunu aktualnu domensku shemu. Globalne naslovnice
postoje samo u JSON-u, osobni izbori ostaju u bazi. Ne isporučuje se izvoz starih
postavki ni pretvorba sheme pri pokretanju.

Aktualni backup format 2 koristi JSON provider `workspace-configuration` i bazni
provider `workspace` s točnom shemom 3. Stari Simbioza formati i uklonjeni skup
globalne naslovnice ne prihvaćaju se. Povrat dijeli GUI lock, validira prije objave
i nakon DB importa mapira uvezene identitete kreatora/naslovnica. Lokalni ID-ovi
koji nisu preneseni arhivom ostaju isti. Kasnija greška vraća JSON i promjene baze.

Izvorna javna adresa i uvezene mape ruta/identiteta određuju prijenos linkova
stranica i privitaka na drugi site. Nerazriješene interne reference prijavljuju
se, ne pogađaju. Lozinke i ciljne putanje pohrane ostaju lokalne. Workspace
ostaje uporabiv bez opcionalnog Backup modula.
