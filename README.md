# BoodoSyncSilvasoft
The plugin synchronises the Silvasoft ERP system with the Shopware version 6 e-commerce solution.




## Use the script in this order:
1. bin/console boodo:merge-guest-customers
2. bin/console boodo:synchronize:customer
3. bin/console boodo:synchronize:products
4. bin/console boodo:synchronize:orders




## Sync only stock and sale price;
**From SilvaSoft to Shopware**
1. bin/console boodo:synchronize:stock

**From Shopware to SilvaSoft**
1. bin/console boodo:synchronize:stock --direction=pus
