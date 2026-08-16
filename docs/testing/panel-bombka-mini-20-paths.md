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
