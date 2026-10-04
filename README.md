GK – Travel-Set Regel-Builder 4.0.0

Neu in 4.0.0
- Mehrere Travel-Set-Produkte gleichzeitig verwalten.
- Jedes Travel-Set-Produkt besitzt eigene Regeln, Varianten-Zuordnungen und Kategorien.
- Bestehende v3.0.0-Konfiguration wird automatisch übernommen; du musst nichts neu einrichten.
- Regeln per Drag & Drop (☰) sortieren.
- Produktgruppen innerhalb einer Regel ebenfalls per Drag & Drop sortieren.
- Travel-Set-Produkte in der Verwaltung per Drag & Drop sortieren.
- Button „6er Standardregeln laden“ entfernt.
- Preisfeld aus Regeln vollständig entfernt.
- Variantenpreise werden ausschließlich in WooCommerce gepflegt und vom Plugin niemals überschrieben.
- Aktueller WooCommerce-Variantenpreis wird nur im Varianten-Auswahlfeld angezeigt.
- Neue automatisch erzeugte Varianten werden ohne Preis erstellt; Preis danach in WooCommerce setzen.
- Produktkarten lassen sich auf-/zuklappen, damit die Seite bei vielen Produkten übersichtlich bleibt.
- Entfernen einer Travel-Set-Konfiguration löscht niemals das WooCommerce-Produkt selbst.
- Warenkorb-/Checkout-/Bestellfunktion arbeitet jetzt produktbezogen mit mehreren Travel-Sets.
- Bestehende Warenkorb-/Bestell-Metadaten aus älteren Versionen bleiben lesbar.

Migration
Beim ersten Laden nach dem Update liest v4 die bisherige Option gkts_settings_v3 und übernimmt:
- ausgewähltes Travel-Set-Produkt
- alle Regeln
- Regel-Reihenfolge
- Varianten-Zuordnungen
- aktiv/inaktiv Status
- Produktgruppen, Anzahlen und Kategorien

Das frühere Preisfeld der Regeln wird bewusst nicht migriert. Die bereits in WooCommerce gespeicherten Variantenpreise bleiben unverändert bestehen.


Version 4.0.1
- Drag-&-Drop-Reihenfolge wird jetzt explizit gespeichert.
- Regel-Reihenfolge synchronisiert WooCommerce-Varianten menu_order.
- Frontend-Varianten-Dropdown folgt der Regel-Reihenfolge.
- Bestehende v4 Einstellungen bleiben unverändert erhalten.