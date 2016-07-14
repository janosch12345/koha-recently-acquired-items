# koha-recently-acquired-items

## getting acquired items

use a cronjob for doing this. f.e. in ubuntu: /etc/cron.daily/koha-recently-acquired-items.sh `php path/to/script.php > /directory/the/webserver/can/see/items.json
