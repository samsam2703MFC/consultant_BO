# Statyczne wyniki

- Routing panelu: 314 deklaracji w `App/Core/Routes/*/*Routes.php`.
- Routing publicznego API: 3 endpointy.
- Framework testowy: nie wykryto.
- TODO/FIXME/debug/ToRefactor: liczne trafienia, w tym brak docelowego flattened recipe i zakomentowane autoryzacje.
- Transakcje PDO: występują lokalnie w części serwisów, ale nie obejmują wszystkich procesów wielotabelowych.
- CSRF: brak trafień implementacyjnych.
- Sekrety: wykryto w `.prod.env`; wartości nie zapisano w artefaktach.

