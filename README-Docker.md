Run the project with Docker (PHP + Apache + MySQL)

Prerequisite: Docker Desktop installed and running on Windows.

1. From the project folder (where `docker-compose.yml` is):

```powershell
docker compose up -d --build
```

2. Wait for containers to start. The database will be initialized from `atitc_database.sql` on first run.

3. Open the app in your browser:

  http://localhost:8000

4. MySQL connection (for tools):
  - Host: `localhost`  Port: `3306`
  - User: `root`  Password: `rootpassword`
  - Database: `atitc_portal`

To stop and remove containers:

```powershell
docker compose down
```

If you want different passwords or ports, edit `docker-compose.yml` accordingly.
