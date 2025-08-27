# BoodoSyncSilvasoft
The plugin synchronises the Silvasoft ERP system with the Shopware version 6 e-commerce solution. In version 3 the sync is chnanged to a schedule task and not via the Event action anymore, also manual trigger and logging are added.
https://developers.silvasoft.nl/

## General use:
After install the customers and orders are sync via a scheduled task, this can also be manuall trigger via the plugin setting. Sync interval set default to 15min, the more orders you get the more you want to sync.
Product are synced based on ```ProductEvents::PRODUCT_WRITTEN_EVENT => 'onProductWritten'``` a product will be UPDATED or will be CREATED in Silvasoft ERP.
<br>
Product are synced based on the SKU, so this need to be the same in both systems.

## Recomendation in Shopware:
Make dure customers create a account, so you don't get any double account as the sync is based on customer email address. Do this via <b>Settings > Log-in & sign-up. Enable setting: 'Create customer account by default'</b>

## Known limitations/issues
1. Product categories are not synced, as the category tree in Silvasoft ERP is limited. All new items are hardcoded link to category "NEW_ITEMS" (Make sure you make this category in Silvasoft ERP)

## Initial sync, use the script in this order:
1. bin/console boodo:merge-guest-customers
2. bin/console boodo:synchronize:customer
<br><i>or use with date string<i>
<br>bin/console boodo:synchronize:customer --from-customer-number=14100
<br>bin/console boodo:synchronize:customer --date=25-06-01
4. bin/console boodo:synchronize:products
5. bin/console boodo:synchronize:orders
<br><i>or use with date string</i>
<br>bin/console boodo:synchronize:orders --date=2025-06-01


## Sync only stock and sale price;
**From SilvaSoft to Shopware**
```
bin/console boodo:synchronize:stock
```
**From Shopware to SilvaSoft**
```
bin/console boodo:synchronize:stock --direction=push
```

# Fields that are synchronized between Shopware and Silvasoft

### Product
```
‘ArticleNumber’, ‘NewName’, ‘NewDescription’, ‘NewSalePrice’, ‘NewUnit’, ‘NewVATPercentage’, ‘EAN’, ‘CategoryName’, ‘NewStockQty’
```
### Customer
```
‘Address_City’, ‘Address_Street’, ‘Address_PostalCode’, ‘Address_CountryCode’, ‘IsCustomer’, ‘CustomerNumber’, ‘Relational_Contact’ => [‘Email’, ‘Phone’, ‘FirstName’, ‘LastName’]
```
### Order
```
‘CustomerNumber’, ‘OrderReference’, ‘OrderStatus’, ‘Order_Contact’ => [‘ContactType’, ‘Email’, ‘Phone’, ‘FirstName’, ‘LastName’], ‘Order_OrderLine’ => [‘ProductNumber’, ‘Quantity’, ‘TaxPc’, ‘UnitPriceExclTax’, ‘Description’], ‘Order_Address’ => [ [‘Address_City’, ‘Address_Street’, ‘Address_PostalCode’, ‘Address_CountryCode’, ‘Address_Type’:‘InvoiceAddress’], [‘Address_City’, ‘Address_Street’, ‘Address_PostalCode’, ‘Address_CountryCode’, ‘Address_Type’:‘ShippingAddress] ]
```

## Scheduled Task
The plugin brings a scheduled task with ‘boodo:silvasoft.stock_update’, with this the stocks of products are fetched from Silvasoft every 15 minutes and imported into Shopware. It would be ideal if the worker(s) could run in the background (preferably not the admin worker) so that you can ensure that the task is always executed at the right time.
