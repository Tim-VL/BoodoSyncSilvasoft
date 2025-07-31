# BoodoSyncSilvasoft
The plugin synchronises the Silvasoft ERP system with the Shopware version 6 e-commerce solution.
https://developers.silvasoft.nl/

## Use the script in this order:
1. bin/console boodo:merge-guest-customers
2. bin/console boodo:synchronize:customer
<br><i>or use with date string</i>
<br>bin/console boodo:synchronize:customer --date=25-06-01
3. bin/console boodo:synchronize:products
4. bin/console boodo:synchronize:orders
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
