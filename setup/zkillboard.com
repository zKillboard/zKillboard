server {
	server_name zkillboard.com;
	server_name ~^(.*)\.zkillboard\.com$;
	listen 443 ssl;
	listen [::]:443 ssl;
	include snippets/self-signed.conf;
	include snippets/ssl-params.conf;

	root        /var/www/zkillboard.com/public/;

	location / {
		try_files $uri /cache/$uri/index.html $uri/ /index.php$is_args$args;
		index index.php;
	}

	location ~ \.php$ {
		try_files $uri =404;
		fastcgi_pass unix:/var/run/php5-fpm.sock;
		fastcgi_index index.php;
		fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
		fastcgi_temp_path /dev/shm/fastcgi/;
		include fastcgi_params;
	}
}
