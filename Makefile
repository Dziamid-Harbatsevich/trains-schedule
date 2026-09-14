# ---------------------------------------------------------------------------
#  Convenience targets. The project works with plain `docker compose`
#  commands as well — the Makefile is only a shortcut.
# ---------------------------------------------------------------------------
.DEFAULT_GOAL := help
.PHONY: help up down restart logs build ps shell db-shell reset nuke lint diagnose

help: ## Показать доступные команды
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
	| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'

up: ## Собрать и запустить (http://localhost:8080)
	docker compose up -d --build

down: ## Остановить контейнеры (данные сохраняются)
	docker compose down

restart: ## Перезапустить контейнер приложения
	docker compose restart app

logs: ## Логи приложения
	docker compose logs -f app

build: ## Пересобрать образ приложения без кэша
	docker compose build --no-cache app

ps: ## Статус контейнеров
	docker compose ps

shell: ## Shell внутри контейнера приложения
	docker compose exec app bash

db-shell: ## Подключиться к MySQL как пользователь приложения
	docker compose exec db mysql -ubelta -pbelta belta_trains

reset: ## Перезалить схему и демо-данные
	FORCE_DB_INIT=1 docker compose up -d --force-recreate app

nuke: ## Остановить и удалить контейнеры вместе с данными БД
	docker compose down -v

diagnose: ## Показать статус и логи, если сайт не открывается
	@echo "=== containers ==="
	@docker compose ps || true
	@echo
	@echo "=== app logs (last 60) ==="
	@docker compose logs --tail=60 app || true
	@echo
	@echo "=== db logs (last 30) ==="
	@docker compose logs --tail=30 db || true
	@echo
	@echo "=== HTTP check ==="
	@curl -sS -m 5 -o /dev/null -w "http://localhost:$${APP_PORT:-8080}/ -> HTTP %{http_code}\n" \
		http://localhost:$${APP_PORT:-8080}/ || echo "no response from the web server"

lint: ## Проверить синтаксис PHP и shell-скриптов
	@for f in public/*.php src/*.php config.php; do php -l "$$f" >/dev/null || exit 1; done
	@bash -n docker/entrypoint.sh && bash -n db/install.sh
	@echo "OK"
