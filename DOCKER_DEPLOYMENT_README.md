# Docker Deployment Guide for SahabFormMaster

This guide provides instructions for deploying the SahabFormMaster application using Docker and Docker Compose.

## Prerequisites

- Docker Engine 20.10 or later
- Docker Compose 2.0 or later
- At least 4GB of available RAM
- At least 10GB of available disk space

## Quick Start

1. **Clone the repository** (if not already done):
   ```bash
   git clone <repository-url>
   cd sahabformmaster
   ```

2. **Start the application**:
   ```bash
   docker-compose up -d
   ```

3. **Access the application**:
   - Main application: http://localhost:8080
   - phpMyAdmin: http://localhost:8081

## Services

The Docker setup includes three services:

### Application (app)
- **Port**: 8080
- **PHP Version**: 8.1 with Apache
- **Extensions**: PDO MySQL, GD, ZIP, MBString, XML, cURL, OPCache
- **Health Check**: Available at `/health.php`

### Database (mysql)
- **Port**: 3306
- **MySQL Version**: 8.0
- **Database**: sahabformmaster
- **Root Password**: rootpassword
- **User**: sahabuser / sahabpass

### phpMyAdmin (phpmyadmin)
- **Port**: 8081
- **Purpose**: Database management interface

## Configuration

### Environment Variables

The application uses the following environment variables (configured in docker-compose.yml):

```yaml
environment:
  - DB_HOST=mysql
  - DB_NAME=sahabformmaster
  - DB_USER=root
  - DB_PASS=rootpassword
```

### Database Initialization

The database is automatically initialized with the schema from `database/sahabformmaster.sql` on first startup.

## Volumes

The following directories are mounted as volumes to persist data:

- `./uploads` - User uploaded files
- `./exports` - Generated export files
- `./generated_papers` - Generated examination papers
- `./config` - Configuration files
- `mysql_data` - MySQL data (Docker managed)

## Usage

### Starting Services

```bash
# Start all services
docker-compose up -d

# Start with logs
docker-compose up

# Start specific service
docker-compose up app
```

### Stopping Services

```bash
# Stop all services
docker-compose down

# Stop and remove volumes
docker-compose down -v
```

### Viewing Logs

```bash
# All services
docker-compose logs -f

# Specific service
docker-compose logs -f app
```

### Rebuilding

```bash
# Rebuild and restart
docker-compose up -d --build

# Rebuild specific service
docker-compose up -d --build app
```

## Health Checks

The application includes health checks that monitor:

- PHP version and extensions
- Database connectivity
- File system permissions
- Memory limits
- Upload limits

Access health status at: http://localhost:8080/health.php

## Database Management

### Using phpMyAdmin

1. Open http://localhost:8081
2. Login with:
   - **Server**: mysql
   - **Username**: root
   - **Password**: rootpassword

### Direct MySQL Access

```bash
# Connect to MySQL container
docker-compose exec mysql mysql -u root -p sahabformmaster

# Enter password: rootpassword
```

## Backup and Restore

### Database Backup

```bash
# Backup database
docker-compose exec mysql mysqldump -u root -prootpassword sahabformmaster > backup.sql

# Backup with timestamp
docker-compose exec mysql mysqldump -u root -prootpassword sahabformmaster > backup_$(date +%Y%m%d_%H%M%S).sql
```

### Database Restore

```bash
# Restore database
docker-compose exec -T mysql mysql -u root -prootpassword sahabformmaster < backup.sql
```

### File Backup

```bash
# Backup uploads and other persistent data
tar -czf backup_$(date +%Y%m%d_%H%M%S).tar.gz uploads/ exports/ generated_papers/
```

## Troubleshooting

### Common Issues

1. **Port conflicts**: Change ports in docker-compose.yml if 8080/3306/8081 are in use
2. **Permission errors**: Ensure Docker has access to project directories
3. **Memory issues**: Increase Docker memory allocation in Docker settings
4. **Database connection errors**: Wait for MySQL health check to pass

### Reset Everything

```bash
# Stop and remove all containers, volumes, and images
docker-compose down -v --rmi all

# Clean up orphaned resources
docker system prune -f

# Restart fresh
docker-compose up -d
```

## Production Deployment

For production deployment:

1. **Change default passwords** in docker-compose.yml
2. **Use environment files** instead of hardcoded values
3. **Configure SSL/TLS** with a reverse proxy
4. **Set up proper logging** and monitoring
5. **Configure backups** and disaster recovery
6. **Use Docker secrets** for sensitive data

### Example Production docker-compose.yml

```yaml
version: '3.8'
services:
  app:
    # ... same as development
    environment:
      - DB_HOST=mysql
      - DB_NAME=${DB_NAME}
      - DB_USER=${DB_USER}
      - DB_PASS=${DB_PASS}
    # Add environment file
    env_file:
      - .env.production
```

## Security Considerations

- Change default database passwords
- Use strong passwords for all services
- Regularly update Docker images
- Monitor access logs
- Implement proper firewall rules
- Use HTTPS in production

## Support

For issues related to Docker deployment:

1. Check container logs: `docker-compose logs`
2. Verify health checks: `curl http://localhost:8080/health.php`
3. Ensure all required ports are available
4. Check file permissions on host directories

## Version Information

- **PHP**: 8.1
- **Apache**: Latest
- **MySQL**: 8.0
- **phpMyAdmin**: Latest
- **Docker Compose**: 3.8
