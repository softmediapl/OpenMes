# Panel Bombka mini - test 20 sciezek

Zlecenie: `PANEL-E2E-20-20260816`, 100 sztuk, 20 partii po 5 sztuk.

Kazda partia przechodzi przez malowanie, suszenie czasowe oraz kontrole jakosci z montazem zawieszki. Scenariusz blokujacy musi zostac zakonczony kontrolowanym odzyskaniem procesu, nie ominieciem walidacji.

| # | Partia testowa | Wariant glowny | Oczekiwany wynik | Status |
|---:|---:|---|---|---|
| 1 | 1 | Pelna sciezka bez odpadu i poprawek | 5 dobrych, kompletne materialy i zamkniete zlecenie partii | OK - malowanie, pelne suszenie i pozytywna kontrola jakosci zakonczone |
| 2 | 3 | Jeden odpad przy malowaniu | 4 dobre, 1 odpad z pojedyncza przyczyna | OK - 4 dobre + 1 LAK-ZAC, partia przekazana do suszenia |
| 3 | 4 | Jedna sztuka do poprawki | 4 dobre, 1 do poprawki, zachowany bilans | OK - 4 dobre + 1 poprawka, bilans kompletny |
| 4 | 5 | Dwie rozne przyczyny odpadu | 3 dobre, dwa wpisy odpadu po 1 sztuce | OK - LAK-ZAC 1 + LAK-KOL 1 zapisane oddzielnie |
| 5 | 6 | Poprawka i odpad jednoczesnie | 3 dobre, 1 poprawka, 1 odpad | OK - 3 dobre + 1 poprawka + 1 LAK-NIE |
| 6 | 7 | Korekta czasu przezbrojenia | czas pracy = czas calkowity minus przezbrojenie | OK PO POPRAWCE - 5 min lacznie, 1 min przezbrojenia, 4 min pracy; modal odswieza czas na zywo |
| 7 | 2 | Wystarczajacy stan materialu | automatyczna rezerwacja i prawidlowe rozliczenie | OK - rezerwacja 5 szt. i 25,50 ml, rozliczenie domyslne |
| 8 | 8 | Dodatkowe pobranie materialu | zwiekszenie rezerwacji i rozliczenie dodatkowej ilosci | OK - rezerwacja farby zwiekszona z 25,50 do 30,50 ml i rozliczona przy zakonczeniu |
| 9 | 9 | Niedobor materialu | blokada startu, czytelny powod, brak zmiany stanu kroku | OK - przy stanie 0 ml modal pokazal brak 25,50 ml i zablokowal potwierdzenie |
| 10 | 9 | Uzupelnienie po niedoborze | domowienie/uzupelnienie, ponowny start i zakonczenie | OK PO POPRAWCE - zamowiono i dostarczono 500 ml, potem zarezerwowano 25,50 ml i zakonczono malowanie |
| 11 | 10 | Obce stanowisko | brak mozliwosci uruchomienia, potem poprawne stanowisko | OK - obcy adres odrzucony; malowanie uruchomione na poprawnym stanowisku i przekazane do suszenia |
| 12 | 11 | Brak kompetencji | blokada i audytowane jednorazowe zastepstwo supervisora | OK PO POPRAWCE - blokada przed doborem materialu, wezwanie, zdalna zgoda, jeden start i zuzycie autoryzacji potwierdzone |
| 13 | 12 | Niepotwierdzona instrukcja | blokada zakonczenia, potwierdzenie i poprawne zakonczenie | OK PO POPRAWCE - zakonczenie nieaktywne do chwili potwierdzenia instrukcji |
| 14 | 13 | Niekompletna checklista | blokada zakonczenia, uzupelnienie checklisty | OK PO POPRAWCE - zakonczenie nieaktywne do zaznaczenia wymaganej pozycji |
| 15 | 1 | Suszenie przez pelny czas | automatyczny timer i wydanie po osiagnieciu minimum | OK |
| 16 | 2 | Proba zbyt wczesnego wydania | blokada, nastepnie wydanie po uplywie czasu | OK |
| 17 | 1 i 2 | Rownolegle suszenie wielu partii | niezalezne timery i poprawne przekazanie kazdej partii | OK |
| 18 | 3-12 | Pelna pojemnosc suszarni | blokada kolejnej partii, start po zwolnieniu miejsca | OK PO POPRAWCE - 10/10 blokuje jedenasta partie; po przekazaniu gotowej partii start odblokowal sie i ponownie zajal 10/10 |
| 19 | 1 | Pozytywna kontrola jakosci i zawieszki | pozytywna bramka oraz rozliczenie zawieszek | OK - 5 dobrych, 6 zawieszek zarezerwowanych zgodnie z BOM |
| 20 | 2 | Negatywny wynik jakosci i odzyskanie | zatrzymanie, zapis problemu/poprawki, kontrolowane zakonczenie | OK - negatywna kontrola zablokowala zakonczenie; po rozwiazaniu problemu zapisana pozytywna kontrola odzyskowa i partia zakonczona |

## Status

Statusy sa uzupelniane podczas testu: `NIEURUCHOMIONY`, `W TOKU`, `OK`, `BLAD`, `OK PO POPRAWCE`.

## Poprawki wykryte podczas przebiegu

- Modal startu i zakonczenia zostal przebudowany pod cele dotykowe 48-56 px.
- Ilosci materialow w operacji uzywaja precyzji jednostki (`szt.` = 0, `ml` = 2).
- Zwykle malowanie nie jest opisywane jako stanowisko pojemnosciowe.
- Odliczanie `fixed_hold` zaczyna sie dopiero po starcie operacji.
- Malowanie nie ma normy ani minimalnego czasu; system rejestruje jedynie rzeczywisty czas start-stop.
- Kroki demonstracyjne przenosza typ stanowiska, dzieki czemu slownik odpadow i kompetencje sa filtrowane prawidlowo.
- Panel konta ludzkiego po zakonczeniu kroku wraca bezposrednio do kolejki, bez otwierania nieaktywnej juz partii.
- Seeder demonstracyjny tworzy saldo zbiorcze magazynu i saldo partii materialowej wymagane przez wydanie na stanowisko.
- Formularz dostawy pokazuje blad salda magazynowego zamiast pozostawiac otwarte okno bez wyjasnienia.
- Liczenie stanu materialu inicjalizuje pole precyzja jednostki, a nie techniczna skala `decimal(4)`.
- Kazda zwykla operacja panelu zapisuje czas start-stop; przezbrojenie jest korekta operatora, a czas pracy wynika z roznicy.
- Modal zakonczenia odswieza czas rzeczywisty do chwili wyslania formularza.
- Wymagana checklista blokuje przycisk zakonczenia przed otwarciem modala, z czytelnym powodem.
- Panel pokazuje zajeta pojemnosc stanowiska przed startem; backend nadal transakcyjnie chroni ostatnie miejsce.
- Brak kompetencji blokuje start przed doborem materialow, a zdalna zgoda supervisora dziala takze dla panelu uruchomionego przez konto ludzkie z wybranym stanowiskiem.
- Wezwanie supervisora jest rozwiazywane dopiero po wykonaniu i zuzyciu autoryzowanej czynnosci.
- Uzupelniono tlumaczenia komunikatow zakonczenia, niedostepnosci i checklisty.

## Audyt ekranow tabletowych

| Widok | Rozdzielczosci | Wynik |
|---|---|---|
| Kolejka stanowiska | 1280x800, 1024x768, 768x1024 | Duze karty i akcje, trzy stale zakladki, czytelne powody blokad; przewija sie tylko lista partii |
| Biezaca operacja | 1024x768, 768x1024 | Podsumowanie, instrukcja, checklista, materialy, timer i akcje mieszcza sie w ukladzie tabletowym; dluga tresc ma wlasny obszar przewijania |
| Suszarnia | 1024x768, 768x1024 | Zajetosc, wolne miejsca, niezalezne timery, gotowosc i przekazanie sa widoczne bez otwierania starego widoku operatora |
| Zakonczenie operacji | 1280x800, 1024x768 | Duze sterowanie iloscia, wiele przyczyn odpadu, automatyczny bilans i czas oraz rozliczenie materialow; stale akcje na dole |
| Materialy stanowiska | 1024x768, 768x1024 | Stan, rezerwacje, dostepna ilosc, liczenie i uzupelnienie sa dostepne dotykowo; nazwa stanowiska wraca do kolejki |

Wniosek: panel obsluguje pelny zakres demonstracyjny Bombki mini na tablecie. Listy o zmiennej liczbie rekordow przewijaja sie pionowo, natomiast ekran pojedynczej operacji i jego glowne akcje nie wymagaja przewijania calej strony.

## Reczny odbior 2026-09-05

Tester wykonywal akcje we wlasnej karcie Chrome, a agent odczytywal biezacy widok.
Stanowisko: Montaz zawieszki i kapturka. Material: `ZAW-MINI-SR`.

| Proba | Zaobserwowany wynik | Status |
|---|---|---|
| Partia #3, zuzycie zgodne z planem | 4 dobre bombki; rezerwacja 5 zawieszek, zuzycie 4; przycisk zakonczenia nieaktywny przed potwierdzeniem. Po zakonczeniu stan 86 -> 82, rezerwacje 0, dostepne 82. Bez recznej korekty stanu. | OK |
| Partia #4, roznica materialowa | 4 dobre bombki, zuzycie 4 zawieszek + strata materialowa 1; odpad wyrobu 0. Po zakonczeniu stan 82 -> 77, rezerwacje 0, dostepne 77. | OK |
| Przestoj: czyszczenie | Po poprawce widoczny aktywny wskaznik z rosnacym licznikiem, przyczyna i uwagi w modalu. Tester zakonczyl przestoj; odczyt Chrome potwierdzil powrot przycisku do Rozpocznij przestoj. | OK PO POPRAWCE |
| Zamowienie uzupelnienia | Klikniecie Zamow uzupelnienie natychmiast wyslalo zgloszenie bez potwierdzenia. Dodano modal z materialem, stanowiskiem, iloscia, Anuluj i Potwierdz zamowienie. Istniejacego zgloszenia nie anulowano. Reczny odbior nowego potwierdzenia pozostaje do wykonania. | W TOKU |

Poprawke przestoju sprawdzono testem backendu start/odczyt/stop (takze izolacja
obcego stanowiska) oraz lokalnym testem komponentu w przegladarce z danymi
testowymi: licznik rosnie, wskaznik wraca do stanu poczatkowego po zakonczeniu.
Pasek miesci sie w 1024x768, 1280x800 i 768x1024. W pionie podpis operatora jest
ukryty, ale przycisk zmiany operatora pozostaje dostepny. Te sprawdzenia nie sa
ponownym wykonaniem wszystkich 20 sciezek ani odbiorem Gate C.

Potwierdzenie uzupelnienia sprawdzono lokalnie w Chrome z izolowanym adapterem
formularza: otwarcie, Anuluj i Escape nie wysylaja zadania; zatwierdzenie wysyla
wlasciwy identyfikator polityki i ilosc; podwojne klikniecie daje jedno zadanie;
podczas wysylania zamkniecie jest zablokowane; blad walidacji pozostaje w modalu
i umozliwia ponowienie. Modal miesci sie w 1024x768, 1280x800 i 768x1024,
a przyciski maja cele dotykowe co najmniej 48 px. Test nie tworzy danych na serwerze.
Frontend: 134 testy OK, build OK. Backend niezmieniony w tej poprawce;
ostatni pelny przebieg przy poprawce przestoju: 2654 testy, 9240 asercji OK.
Logika ilosci pozostaje wspolna: dodatni issue_increment albo null, gdy ilosc
wylicza serwer wedlug docelowego zapasu. Stary /operator zachowuje swoje zachowanie.

### Potwierdzenie anulowania uzupelnienia

W karcie testera po wdrozeniu b6152d01 otwarto i zamknieto potwierdzenie zamowienia
kapturkow: widoczny material, stanowisko i 250 szt.; po Anuluj pozostalo jedno
istniejace zgloszenie srebrnych zawieszek. Nie wysylano dodatkowego zamowienia.
Tester nastepnie zglosil niespojnosc: anulowanie wciaz otwieralo systemowe pytanie
przegladarki. Panel korzysta teraz z tego samego modala dla obu akcji, z przyciskami
Zostaw zgloszenie / Anuluj zgloszenie. Pokazuje material i zamowiona ilosc;
zamkniecie nie wysyla zadania, wysylanie blokuje powtorzenia i zamkniecie,
blad serwera pozostaje widoczny. Akcja dotyczy wybranego ID zgloszenia.
Backend i starszy /operator pozostaja bez zmian.

Testy: 141 frontendowych OK, build OK; izolowany Chrome sprawdzil oba tryby
na 1024x768, 1280x800 i 768x1024 (odrzucenie, Escape, podwojne klikniecie,
wysylanie, blad i ponowienie). Potwierdzono brak wyjscia modala poza ekran
i cele dotykowe co najmniej 48 px. Po wdrozeniu tester otworzyl anulowanie
kapturkow (250 szt.). Zostaw zgloszenie zamknelo modal bez zmiany statusu.
Ponowne otwarcie i potwierdzenie Anuluj zgloszenie usunelo je z otwartych:
licznik 0, Zapas prawidlowy, stan kapturkow nadal 1000 szt. Odbior anulowania OK.

### Pomoc przy pustej kolejce

Po powrocie do stanowiska wszystkie trzy liczniki kolejki wynosily 0. Tester
otworzyl Pomoc; Zglos problem i Wezwij supervisora byly nieaktywne. Instrukcja
bez wybranej operacji pozostaje nieaktywna, ale pomoc stanowiskowa jest dostepna.

- Zgloszenie z kolejki/materialow dotyczy stanowiska, bez automatycznego wyboru
  pierwszego zlecenia z kolejki. Widok operacji zachowuje wybrany krok i zlecenie.
- Serwer bierze stanowisko z uwierzytelnionego terminala/sesji, a autora z PIN-u.
  Podany w formularzu obcy identyfikator stanowiska nie zmienia przypisania.
- Zgloszenia korzystaja z IssueService, ze sprawdzeniem aktywnego typu. Dodano
  nullable issues.workstation_id; bez usuwania danych lub automatycznego backfillu.
- Zgloszenie bez zlecenia nie blokuje przypadkowych zlecen ani nie udziela zgody
  produkcyjnej. Dotychczasowe blokowanie konkretnego zlecenia jest zachowane.
- Supervisor widzi wezwanie stanowiskowe, moze je przyjac i rozwiazac. Nie widzi
  przy nim autoryzacji wyjatku dla nieistniejacej operacji. Zwykle zgloszenia
  na wspolnej liscie problemow pokazuja teraz stanowisko.
- Na serwerze istniejacy aktywny typ pomocy ma ID 8, nie jest blokujacy.
  Konfiguracji nie zmieniano.

Testy ukierunkowane: 14 backendowych / 91 asercji OK; 145 frontendowych OK;
build OK. Izolowany Chrome: aktywne akcje pomocy bez zadania, null zlecenia/kroku
w formularzach, walidacja, przyjecie/rozwiazanie przez supervisora bez zgody na
wyjatek. Formularze mieszcza sie w 1024x600, 1024x768, 1280x800 i 768x1024.
Reczne wyslanie zgloszen w karcie testera pozostaje do wykonania po wdrozeniu.
Pelna regresja backendu: 2668 testow / 9331 asercji OK (09:07), cztery istniejace
ostrzezenia PHPUnit o przestarzalych zachowaniach. Nie wykonano ponownie Gate C.

### Odbior pomocy i formularz rozwiazania (2026-09-05)

Tester utworzyl przy pustej kolejce zgloszenia #4 (Test - pomoc przy pustej
kolejce) i #5 (Wezwanie supervisora z panelu operatora). Odczyt serwera oraz
zrzut ekranu administratora potwierdzily stanowisko 36, operatora 42, brak
zlecenia/kroku i status OPEN. Zgloszenia sa widoczne w /admin/issues.

Klikniecie Rozwiaz otwieralo natywny prompt przegladarki. Wspolna lista
administratora/supervisora otwiera teraz modal MES z tytulem wybranego
zgloszenia, notatka do 2000 znakow, Anuluj i Rozwiaz. Zachowano endpoint,
uprawnienia i opcjonalnosc notatki. Brak zmian backendu lub danych.
Zamkniecie/anulowanie nie wysyla zadania; zapis blokuje ponowne klikniecie
i zamkniecie, a blad walidacji zachowuje notatke oraz pozwala ponowic zapis.

Weryfikacja: 151 testow frontendu OK (6 nowych), build OK, diff --check OK.
Izolowany Chrome z rzeczywistym modalem i kontrolowanym transportem:
admin/supervisor x 1024x600, 1024x768, 1280x800, 768x1024. Sprawdzono focus,
anulowanie, Escape, reset notatki po ponownym otwarciu, payload, podwojne
klikniecie, stan zapisu, walidacje, ponowienie i zamkniecie po sukcesie.
Formularz miesci sie na ekranie; przyciski maja co najmniej 48 px.
PHP nie uruchamiano ponownie dla tej zmiany frontendowej (wynik powyzej).
Nie rozwiazywano automatycznie zgloszen #4/#5. Reczny odbior nowego modalu
oraz zapis rozwiazania pozostaja nastepnym krokiem testera po wdrozeniu.

### Kompaktowe dzialania listy problemow (2026-09-05)

Na prosbe testera cztery przyciski w wierszu zastapiono pojedynczym przyciskiem
Opcje (ikona trzech kropek). Menu zachowuje Dyspozycje, Akcje oraz operacje
dostepne dla aktualnego statusu: Potwierdz/Rozwiaz/Zamknij. Otwarcie menu niczego
nie zapisuje, Rozwiaz nadal otwiera modal notatki. ResourceTable ma opcjonalny
tryb actionsDisplay=menu; pozostale listy zachowuja przyciski.

Wykorzystano ActionMenu z systemu UI, dodajac opcjonalny portal poza obszarem
przewijania tabeli, pozycjonowanie przy krawedziach oraz dotykowe pozycje 48 px.
Trigger ma 48x48 px, etykiete dostepnosci z tytulem zgloszenia i tooltip Opcje.
Escape oddaje focus, klikniecie poza menu je zamyka; klawiatura obsluguje
strzalki, Home/End i Enter. Na waskiej liscie zmniejszono tylko odstepy poziome
komorek, bez usuwania kolumn i zmniejszania tekstu.

Weryfikacja: 154 testy frontendu OK, build OK, diff --check OK. Izolowany Chrome
z rzeczywistym IssuesIndex/ResourceTable/DataTable/ActionMenu, danymi testowymi
i kontrolowanym transportem: 1024x600, 1024x768, 1280x800, 768x1024. Uwzgledniono
szerokosc 256 px paska bocznego i padding aplikacji; tabela nie przewija sie
poziomo (odpowiednio 702/702/958/718 px). Zweryfikowano menu wszystkich czterech
statusow, anulowanie modalu, przyjecie wlasciwego ID, focus, Escape, zamykanie
poza menu, brak obciecia i dotychczasowy nieportalowy ActionMenu. Wieksza liczba
wierszy nadal wymaga przewijania pionowego/paginacji. Backend bez zmian;
nie wysylano rzeczywistych akcji dla zgloszen testera.
