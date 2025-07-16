# BoodoSyncSilvasoft
The plugin synchronises the Silvasoft ERP system with the Shopware version 6 e-commerce solution.


## Use the script in this order:
bin/console boodo:merge-guest-customers
bin/console boodo:synchronize:customer
bin/console boodo:synchronize:products
bin/console boodo:synchronize:orders


## Sync only stock and sale price;
From SilvaSoft to Shopware
bin/console boodo:synchronize:stock

From Shopware to SilvaSoft
bin/console boodo:synchronize:stock --direction=pus
