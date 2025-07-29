up:
	docker compose up --build

down:
	docker compose down

# Reiniciar apenas as APIs
restart-api:
	docker compose restart api01 api02

# Reiniciar apenas os workers
restart-workers:
	docker compose restart worker-payments worker-health

# Mostrar logs em tempo real (todos os serviços)
logs:
	docker compose logs -f --tail=100

# Subir containers em background
upd:
	docker compose up -d --build

clean:
	docker compose down -v --remove-orphans

# Rebuild sem cache
rebuild:
	docker compose build --no-cache

.PHONY: up down restart-api restart-workers logs logs-% sh-% upd clean k6 rebuild
