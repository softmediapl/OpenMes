# Panel Bombka mini - test 20 sciezek

Zlecenie: `PANEL-E2E-20-20260816`, 100 sztuk, 20 partii po 5 sztuk.

Kazda partia przechodzi przez malowanie, suszenie czasowe oraz kontrole jakosci z montazem zawieszki. Scenariusz blokujacy musi zostac zakonczony kontrolowanym odzyskaniem procesu, nie ominieciem walidacji.

| Partia | Wariant glowny | Oczekiwany wynik |
|---:|---|---|
| 1 | Pelna sciezka bez odpadu i poprawek | 5 dobrych, kompletne materialy i zamkniete zlecenie partii |
| 2 | Jeden odpad przy malowaniu | 4 dobre, 1 odpad z pojedyncza przyczyna |
| 3 | Jedna sztuka do poprawki | 4 dobre, 1 do poprawki, zachowany bilans |
| 4 | Dwie rozne przyczyny odpadu | 3 dobre, dwa wpisy odpadu po 1 sztuce |
| 5 | Poprawka i odpad jednoczesnie | 3 dobre, 1 poprawka, 1 odpad |
| 6 | Korekta czasu przezbrojenia | czas pracy = czas calkowity minus przezbrojenie |
| 7 | Wystarczajacy stan materialu | automatyczna rezerwacja i prawidlowe rozliczenie |
| 8 | Dodatkowe pobranie materialu | zwiekszenie rezerwacji i rozliczenie dodatkowej ilosci |
| 9 | Niedobor materialu | blokada startu, czytelny powod, brak zmiany stanu kroku |
| 10 | Uzupelnienie po niedoborze | domowienie/uzupelnienie, ponowny start i zakonczenie |
| 11 | Obce stanowisko | brak mozliwosci uruchomienia, potem poprawne stanowisko |
| 12 | Brak kompetencji | blokada i audytowane jednorazowe zastepstwo supervisora |
| 13 | Niepotwierdzona instrukcja | blokada zakonczenia, potwierdzenie i poprawne zakonczenie |
| 14 | Niekompletna checklista | blokada zakonczenia, uzupelnienie checklisty |
| 15 | Suszenie przez pelny czas | automatyczny timer i wydanie po osiagnieciu minimum |
| 16 | Proba zbyt wczesnego wydania | blokada, nastepnie wydanie po uplywie czasu |
| 17 | Rownolegle suszenie wielu partii | niezalezne timery i poprawne przekazanie kazdej partii |
| 18 | Pelna pojemnosc suszarni | blokada kolejnej partii, start po zwolnieniu miejsca |
| 19 | Pozytywna kontrola jakosci i zawieszki | pozytywna bramka oraz rozliczenie zawieszek |
| 20 | Negatywny wynik jakosci i odzyskanie | zatrzymanie, zapis problemu/poprawki, kontrolowane zakonczenie |

## Status

Statusy sa uzupelniane podczas testu: `NIEURUCHOMIONY`, `OK`, `BLAD`, `OK PO POPRAWCE`.
