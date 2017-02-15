server {
	listen 2096 ssl;
	listen [::]:2096 ssl;
	server_name wss.zkillboard.com;

	include snippets/self-signed.conf;
	include snippets/ssl-params.conf;

	location / {
		proxy_pass http://127.0.0.1:15241;
		proxy_http_version 1.1;
		proxy_set_header   Upgrade $http_upgrade;
		proxy_set_header   Connection "upgrade";
		proxy_set_header Host $host;
		proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
	}

	access_log off;
}
