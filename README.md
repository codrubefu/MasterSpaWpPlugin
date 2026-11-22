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
- Prețurile, SKU-ul și stocul se salvează prin `WC_Product`: `_price`, tabela lookup și cache-ul rămân sincronizate, iar un preț redus care a dispărut din API se șterge.
- Un singur import rulează odată (lock `masterspa_import_lock`, eliberat automat după 15 minute dacă request-ul a crăpat).
- Opțiuni: `Create + Update` / `Update Only`, `Dry Run`, **produse lipsă din feed → coș de gunoi**. Se aplică produselor marcate `_masterspa_imported` sau cu SKU `MSPA-*`. Se pot restaura, iar dacă reapar în feed revin automat. Dacă ar dispărea peste 50% dintre ele (cel puțin 10 produse importate), nu se mută nimic și apare un avertisment în log.

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
- URL-ul webhook e obligatoriu: comenzile nu se mai trimit niciodată la `api_endpoint` (feed-ul de produse).
- Payload: `WC_Order::get_data()` + `items`, `judet`, `custom_info`. Din meta-urile comenzii se trimit doar `*_billing_*`, `*_shipping_*`, `_order_info` și `is_vat_exempt` (filtrul `masterspa_order_meta_key_allowed`). `product_meta_input` conține doar SKU, `_masterspa_art`, `_masterspa_pretdesc`, prețurile și `_masterspa_web_sale` (filtrul `masterspa_order_product_meta_keys`). Tot payload-ul trece prin filtrul `masterspa_order_payload`.
- **La eșec** (server indisponibil sau răspuns non-2xx): comanda trece în *on-hold*, clientul primește emailul on-hold, suportul primește un email, iar trimiterea se reîncearcă după 5, 15, 30 de minute, apoi din oră în oră (~24 de ore, 27 de încercări). La succes, comanda revine la *processing*.
- Când se termină încercările, suportul primește un email **URGENT**.
- În lista de comenzi, coloana **MasterSpa** arată starea: sincronizat / în așteptare / eșuat (n). Poți filtra cu `?masterspa_sync=failed`, iar widget-ul din Dashboard are link direct.
- Din pagina comenzii: **Acțiuni comandă > MasterSpa: retrimite comanda către server**.
- Meta pe comandă: `_masterspa_sync_status` (`pending|synced|failed`), `_masterspa_sync_attempts`, `_masterspa_sync_error`.

## Beneficiari în coș

Se activează din setări („Beneficiar în coș”). Funcționează în coșul clasic (modal sub fiecare produs) și în blocul Cart (Store API, `assets/js/cart-block-subscribers.js`). Pentru fiecare bucată dintr-un pachet spa, **prenumele, numele și un email valid sunt obligatorii**: checkout-ul (clasic sau block) e blocat până se completează. Datele ajung ca meta pe articolele comenzii (`Abonat - Prenume [1]` etc.).

## Log-uri

**WooCommerce > MasterSpa Logs**: importuri, erori, comenzi și request-urile HTTP către server (tip `http`), cu filtru după tip.

Request-urile HTTP se loghează fără date personale: `X-API-Secret`, `Authorization` și `Idempotency-Key` apar ca `***`, payload-ul e rezumat la „order #id, N items, total”, iar răspunsul e trunchiat la 2000 de caractere. Log-urile mai vechi de **Păstrare log-uri (zile)** (implicit 30) se șterg zilnic (acțiunea `masterspa_cleanup_logs`), inclusiv din tabela veche `wp_masterspa_logs`.

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
