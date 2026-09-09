# Validação executada - Pellissari Notification 0.1.2

Data: 09/09/2026.

## Resultados

- Sintaxe: 14 arquivos PHP aprovados por PHP CLI 8.4.23; JavaScript aprovado por Node.js 22.16.0.
- Backend: 47 verificações isoladas aprovadas, com classes do GLPI e banco simulados.
- Interface: 24 verificações aprovadas em Chromium/Playwright, com API simulada, em larguras de 1440 e 390 pixels.
- Nenhuma exceção JavaScript observada nesses fluxos. A prévia não fez requisições externas.

## Limites desta validação

Não foi executada uma instância real do GLPI nem MySQL/MariaDB. A criação de tabelas, o carregamento do plugin pelo GLPI, as rotas, os tokens e a integração dos hooks ainda precisam ser validados no seu ambiente. Os testes com dublos não comprovam compatibilidade real. Não foram feitos testes de carga nem disputa concorrente entre processos/abas no servidor.

A prévia HTML representa a interface implementada, mas usa dados fictícios e respostas simuladas. Não é uma gravação do plugin instalado no GLPI.

## Verificações de interface

1. Initial unread badge
2. Four individual self-test controls
3. Live color hex label
4. Self-test renders popup
5. Unsaved selected color in self-test
6. Bell opens notification center
7. Self-test also present in history
8. Type filter
9. Mark one notification read
10. Unread filter hides read records
11. Mark all read empties unread view
12. Badge hidden after mark all read
13. Escape closes panel
14. User search results
15. User-specific rules visible
16. User override persisted in mock API
17. Global pause reflected by client
18. Self-test works while real notifications paused
19. No browser JavaScript errors on desktop
20. Standalone preview makes no external requests
21. No horizontal overflow on mobile
22. Popup on mobile
23. History on mobile
24. No browser JavaScript errors on mobile

## Saída dos testes PHP isolados

```text
PASS 1 - four configurable event types
PASS 2 - hex colors normalized
PASS 3 - CSS injection rejected
PASS 4 - unknown event type rejected
PASS 5 - invalid polling interval rejected
PASS 6 - root admin can configure
PASS 7 - entity-only administrator cannot configure global rules
PASS 8 - GET mutation rejected
PASS 9 - POST without plugin CSRF rejected
PASS 10 - valid POST passes independent CSRF check
PASS 11 - requester link is not an assignment
PASS 12 - direct assignment only reaches assigned user
PASS 13 - repeated actor hook is idempotent
PASS 14 - group distribution excludes inactive and helpdesk-only users and duplicates
PASS 15 - born-assigned fallback does not duplicate actor notifications
PASS 16 - unrelated ticket changes ignored
PASS 17 - unchanged status ignored
PASS 18 - status change reaches direct assignee only
PASS 19 - public requester comment notifies direct assignee
PASS 20 - same followup cannot create duplicates
PASS 21 - private comments excluded
PASS 22 - technician comment is not requester comment
PASS 23 - problem followup cannot leak into ticket with same numeric ID
PASS 24 - per-user disabled rules block new records
PASS 25 - inherit removes per-user overrides
PASS 26 - individual enable overrides event default
PASS 27 - master switch overrides individual enable
PASS 28 - self-test works when real notifications disabled
PASS 29 - test recipient is server-session admin only
PASS 30 - technician cannot generate admin test
PASS 31 - technician cannot view admin test
PASS 32 - own notification visible
PASS 33 - other user notification hidden
PASS 34 - IDOR read mutation does not affect other user
PASS 35 - atomic toast claim only succeeds once
PASS 36 - revoked ticket rights hide existing history
PASS 37 - active-entity restriction checked at display
PASS 38 - followup made private later is hidden
PASS 39 - unread count excludes other users and tests
PASS 40 - mark-read stores both read and toast flags
PASS 41 - optional self-action suppression works
PASS 42 - history filters by owner and ACL
PASS 43 - read-all does not consume events newer than high-water mark
PASS 44 - no hidden hook failures occurred
PASS 45 - admin UI has four self-test controls
PASS 46 - admin UI carries native and plugin CSRF tokens
PASS 47 - idempotent install uses three private tables

All 47 isolated checks passed. No live GLPI or SQL server was used.
```
