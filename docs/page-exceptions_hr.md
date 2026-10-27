# Iznimke pristupa stranici

Otvorite **Upravljaj stranicom i dozvolama → Izravna dopuštenja korisnicima i grupama**. Birači korisnika i grupa pretražuju poslužitelj i vraćaju ograničene stranice rezultata; klik na polje grupe nudi i prvu stranicu, uključujući Javno i Svi prijavljeni. Postojeće dodjele izostavljaju se iz prijedloga.

Izričita dodjela vrijedi samo za odabrani čvor. Korisniku ili uključenoj grupi može dati čitanje, uređivanje ili objavu uz postojeće provjere prava stranice. Javno daje samo čitanje; krivotvorene zastavice uređivanja/objave zanemaruju se. Te dodjele ne daju upravljanje područjem, pristup podređenim stranicama niti pravo na nepovezan HTML ili ankete. Naslijeđena ograničenja zadržavaju zasebnu ulogu sužavanja prava.

Privatno područje pojavljuje se na popisu ako trenutačni posjetitelj ima opći pristup ili barem jednu čitljivu izravno dopuštenu stranicu/anketu. Stablo izravno dopuštenu stranicu čiji je roditelj nedostupan prikazuje bez otkrivanja naziva predaka. Uklanjanje posljednje iznimke ili članstva u grupi ponovno skriva područje. Nacrti/arhivirane ankete ne stvaraju javne stavke popisa područja.

Dodavajuća migracija `resources/migrations/workspace_subject_permissions.php` čuva postojeća korisnička prava. Nova početna migracija uključuje je; postojeći domaćini mogu kopirati pomoćnu migraciju u novu migraciju aplikacije ili ponovno izvršiti idempotentni početni predložak. Primijeniti nakon sigurnosne kopije s provjerenim povratom. Grupne/javne dodjele uključene su u prijenos prava stranice/područja i definicije backupa baze.
