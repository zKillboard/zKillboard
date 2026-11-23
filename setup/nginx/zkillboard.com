server {
	listen 443 ssl;
	server_name zkillboard.com;
	include snippets/self-signed.conf;
	include snippets/ssl-params.conf;

	root        /var/www/zkillboard.com/public/;

	location = /websocket/ {
			proxy_pass http://127.0.0.1:15241;
			proxy_http_version 1.1;
			proxy_set_header   Upgrade $http_upgrade;
			proxy_set_header   Connection "upgrade";
			proxy_set_header Host $host;
			proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
	}

	location / {
		try_files $uri $uri/ /index.php$is_args$args;
		index index.php;
	}

	location ~ \.php$ {
		try_files $uri =404;
		fastcgi_cache off;
		fastcgi_pass unix:/var/run/php/php-fpm.sock;
		fastcgi_index index.php;
		fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
		fastcgi_read_timeout 90;
		fastcgi_buffers 256 4k;
		fastcgi_temp_path /dev/shm/fastcgi/;
		include fastcgi_params;
	}

	set_real_ip_from 103.21.244.0/22;
	set_real_ip_from 103.22.200.0/22;
	set_real_ip_from 103.31.4.0/22;
	set_real_ip_from 104.16.0.0/13;
	set_real_ip_from 104.24.0.0/14;
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
	set_real_ip_from 2400:cb00::/32;
	set_real_ip_from 2606:4700::/32;
	set_real_ip_from 2803:f800::/32;
	set_real_ip_from 2405:b500::/32;
	set_real_ip_from 2405:8100::/32;
	set_real_ip_from 2c0f:f248::/32;
	set_real_ip_from 2a06:98c0::/29;

	real_ip_header CF-Connecting-IP;

}
