proxy_cache_path /db/zkb.proxy/ levels=1:2 keys_zone=zkb:10m max_size=10g inactive=62m use_temp_path=off;

server {
	server_name zkillboard.com;
	server_name ~^(.*)\.zkillboard\.com$;
	listen 80;
	listen 443 ssl;
	listen [::]:443 ssl;
	include snippets/self-signed.conf;
	include snippets/ssl-params.conf;

	root        /var/www/zkillboard.com/public/;

	location = / {
		proxy_cache_valid 200 1m;
		expires 1m;
		include "zkb-upstream.conf";
	}

	location ~ ^/partial/ {
		proxy_cache_valid 200 1m;
		expires 1m;
		include "zkb-upstream.conf";
	}

	location ~ ^/(information|api|autocomplete|ztop|google)/ {
		proxy_cache_valid 200 60m;
		include "zkb-upstream.conf";
	}

	location ~ ^/(kill|related|br|kills|character|corporation|alliance|faction|ship|group|system|location|wars|war|map|bigisk|top|item)/ {
		proxy_cache_valid 200 5m;
		include "zkb-upstream.conf";
	}

	location / {
		proxy_pass http://localhost:12345;
	}

	set_real_ip_from 103.21.244.0/22;
	set_real_ip_from 103.22.200.0/22;
	set_real_ip_from 103.31.4.0/22;
	set_real_ip_from 104.16.0.0/12;
	set_real_ip_from 108.162.192.0/18;
	set_real_ip_from 131.0.72.0/22;
	set_real_ip_from 141.101.64.0/18;
	set_real_ip_from 162.158.0.0/15;
	set_real_ip_from 172.64.0.0/13;
	set_real_ip_from 173.245.48.0/20;
	set_real_ip_from 188.114.96.0/20;
	set_real_ip_from 190.93.240.0/20;
	set_real_ip_from 197.234.240.0/22;
	set_real_ip_from 198.41.128.0/17;
	set_real_ip_from 199.27.128.0/21;
	set_real_ip_from 2400:cb00::/32;
	set_real_ip_from 2606:4700::/32;
	set_real_ip_from 2803:f800::/32;
	set_real_ip_from 2405:b500::/32;
	set_real_ip_from 2405:8100::/32;
	real_ip_header CF-Connecting-IP;

	#access_log off;
	#log_not_found off; 
}
