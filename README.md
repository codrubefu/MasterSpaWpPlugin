# MasterSpaWpPlugin

Plugin WordPress/WooCommerce pentru Mirage MedSPA:

- **importă pachetele spa** din API-ul MasterSpa ca produse WooCommerce (manual sau automat, pe loturi);
- **trimite comenzile spa** către serverul MasterSpa, cu reîncercare automată când serverul nu răspunde;
- **câmpuri de checkout**: județ și telefon obligatorii, mapare județ → ID din tabela `judet`;
- **beneficiari în coș** (opțional): datele persoanei care folosește fiecare pachet;
- **design propriu** pentru magazin și pagina de produs (`templates/`, `assets/css/storefront.css`).

Cerințe: WordPress 6+, WooCommerce 7+ (testat cu 10.4), PHP 7.0+. Compatibil cu HPOS și cu blocurile Cart/Checkout.

## Instalare

1. Copiază folderul în `wp-content/plugins/` și activează pluginul.
2. **Setări > MasterSpa Settings**: endpoint API, header `X-API-Secret`, URL-ul webhook pentru comenzi, email suport, maparea județelor.

## Import produse

**Setări > MasterSpa Settings > Rulează Import Acum**, sau automat (Cron: orar / de două ori pe zi / zilnic).

- Importul aduce feed-ul o singură dată, apoi îl procesează **pe loturi** (`Batch Size`) în fundal, prin Action Scheduler (WooCommerce > Status > Scheduled Actions, grupul `masterspa`). Progresul apare pe pagina de setări și în widget-ul din Dashboard. Un import blocat de peste o oră poate fi anulat sau înlocuit.
- Produsele **nemodificate** în API (același hash al datelor + același status de produs) nu sunt rescrise. Bifează „Forțează actualizarea” ca să le rescrii pe toate. La schimbarea mapării API → produs, crește `MasterSpa_Importer::MAPPING_VERSION`.
- Opțiuni: `Create + Update` / `Update Only`, `Dry Run`, ștergerea produselor care lipsesc din feed.

### Formatul API

Răspunsul este o listă de produse (direct, sau în cheia `data` sau `products`):

| Câmp | Folosit pentru |
|---|---|
| `art` | numele produsului (obligatoriu) |
| `sku` / `id` / `cod` | SKU (`MSPA-<id>`); dacă lipsesc, un hash din nume + clasă + grupă |
| `pret[]` (`pret`, `pretdesc`) | un preț → preț normal; mai multe → minimul e preț redus, maximul e preț normal. `pretdesc` prețului aplicat se adaugă la nume („art – pretdesc”) |
| `summary` / `BriefWebSummary`, `info1`, `info2` | descrierea (câte un paragraf); `desc1` dacă lipsesc toate |
| `clasa`, `grupa` | categorii: `spa > clasa > grupa` |
| `webSale` | `0` → produsul nu se poate cumpăra online; în locul butonului apare mesajul configurabil |

## Comenzi trimise către server

Când o comandă cu produse spa ajunge în **processing**, trimiterea către webhook se programează în fundal (Action Scheduler), deci checkout-ul nu așteaptă serverul.

- Fiecare request are headerul `Idempotency-Key: <order_key>`, ca serverul să poată ignora trimiterile duplicate.
- **La eșec** (server indisponibil sau răspuns non-2xx): comanda trece în *on-hold*, clientul primește emailul on-hold, suportul primește un email, iar trimiterea se reîncearcă după 5, 15, 30 de minute, apoi din oră în oră (~24 de ore, 27 de încercări). La succes, comanda revine la *processing*.
- Când se termină încercările, suportul primește un email **URGENT**.
- În lista de comenzi, coloana **MasterSpa** arată starea: sincronizat / în așteptare / eșuat (n). Poți filtra cu `?masterspa_sync=failed`, iar widget-ul din Dashboard are link direct.
- Din pagina comenzii: **Acțiuni comandă > MasterSpa: retrimite comanda către server**.
- Meta pe comandă: `_masterspa_sync_status` (`pending|synced|failed`), `_masterspa_sync_attempts`, `_masterspa_sync_error`.

## Beneficiari în coș

Se activează din setări („Beneficiar în coș”). Funcționează în coșul clasic (modal sub fiecare produs) și în blocul Cart (Store API, `assets/js/cart-block-subscribers.js`). Pentru fiecare bucată dintr-un pachet spa, **prenumele, numele și un email valid sunt obligatorii**: checkout-ul (clasic sau block) e blocat până se completează. Datele ajung ca meta pe articolele comenzii (`Abonat - Prenume [1]` etc.).

## Log-uri

**WooCommerce > MasterSpa Logs**: importuri, erori, comenzi și request-urile HTTP către server (tip `http`), cu filtru după tip.

## Scripturi

```bash
# Șterge TOATE produsele și categoriile WooCommerce (doar din CLI)
docker exec wordpress-wordpress-1 php /var/www/html/wp-content/plugins/MasterSpaWpPlugin/bin/purge-products.php --dry-run
docker exec wordpress-wordpress-1 php /var/www/html/wp-content/plugins/MasterSpaWpPlugin/bin/purge-products.php --yes
```

## Teste

Teste unitare fără WordPress (PHPUnit 9 + Brain Monkey):

```bash
composer install
composer test
```

## Dezinstalare

Ștergerea pluginului din WordPress elimină opțiunile, tabelele de log, cron-ul și acțiunile programate. Produsele și comenzile rămân.
