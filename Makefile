.PHONY: up down test logs worker shell migrate fresh metrics monitor prometheus grafana logs-metrics rabbitmq rabbitmq-ui rabbitmq-logs rabbitmq-stats rabbitmq-test rabbitmq-workers rabbitmq-worker-logs rabbitmq-worker-restart rabbitmq-worker-status

up:
	docker-compose up -d
	@echo "Services started"
	@echo "API: http://localhost:80"
	@echo "Prometheus: http://localhost:9090"
	@echo "Grafana: http://localhost:3000 (admin/admin)"

down:
	docker-compose down -v

build:
	docker-compose build

logs:
	docker-compose logs -f app

worker:
	docker-compose exec app php artisan queue:work --queue=high,default --sleep=3 --tries=3 --timeout=60

worker-stream:
	docker-compose exec app php artisan events:process

shell:
	docker-compose exec app bash

migrate:
	docker-compose exec app php artisan migrate

fresh:
	docker-compose exec app php artisan migrate:fresh --seed

test:
	docker-compose exec app php artisan test

load-test:
	python3 load_test.py

metrics:
	@echo "=== Prometheus Metrics ==="
	curl -s http://localhost:9090/api/v1/targets | jq '.data.activeTargets[] | select(.labels.job | contains("event")) | {job: .labels.job, health: .health, lastScrape: .lastScrape}'
	@echo "\n=== Event Service Metrics ==="
	curl -s http://localhost/api/v1/metrics | grep -E '(events_|queue_|redis_)' | head -20

monitor:
	@echo "Opening monitoring dashboards..."
	open http://localhost:3000  # Grafana
	open http://localhost:9090  # Prometheus

prometheus:
	@echo "Prometheus targets status:"
	curl -s http://localhost:9090/api/v1/targets | jq -r '.data.activeTargets[] | "\(.labels.job): \(.health) (last: \(.lastScrape))"'

grafana:
	@echo "Grafana: http://localhost:3000 (admin/admin)"
	open http://localhost:3000 || xdg-open http://localhost:3000 || echo "Open manually: http://localhost:3000"

logs-metrics:
	docker-compose logs --tail=50 prometheus grafana redis-exporter

rabbitmq:
	@echo "RabbitMQ Management UI: http://localhost:15672"
	@echo "Username: admin"
	@echo "Password: admin123"
	open http://localhost:15672 || xdg-open http://localhost:15672

rabbitmq-ui:
	docker-compose exec rabbitmq rabbitmqadmin list queues name messages messages_ready messages_unacknowledged

rabbitmq-logs:
	docker-compose logs --tail=100 rabbitmq

rabbitmq-stats:
	@echo "=== RabbitMQ Queue Stats ==="
	curl -s -u admin:admin123 http://localhost:15672/api/queues | jq '.[] | {name: .name, messages: .messages, messages_ready: .messages_ready, state: .state}'

rabbitmq-test:
	@echo "Testing RabbitMQ connection..."
	docker-compose exec app php artisan rabbitmq:test

rabbitmq-consume:
	docker-compose exec app php artisan rabbitmq:consume high_priority

rabbitmq-workers:
	docker-compose exec app supervisorctl start rabbitmq-high-worker:*
	docker-compose exec app supervisorctl start rabbitmq-normal-worker:*

rabbitmq-worker-logs:
	@echo "=== High Priority Workers ==="
	docker-compose exec app tail -f storage/logs/rabbitmq-high-worker.log
	@echo "\n=== Normal Workers ==="
	docker-compose exec app tail -f storage/logs/rabbitmq-normal-worker.log

rabbitmq-worker-restart:
	docker-compose exec app touch /tmp/restart-workers
	docker-compose exec app supervisorctl signal HUP rabbitmq-high-worker:*
	docker-compose exec app supervisorctl signal HUP rabbitmq-normal-worker:*

rabbitmq-worker-status:
	docker-compose exec app supervisorctl status rabbitmq-high-worker:*
	docker-compose exec app supervisorctl status rabbitmq-normal-worker:*

rabbitmq-test-message:
	@echo "Sending test message to RabbitMQ..."
	curl -X POST http://localhost/api/v1/events \
		-H "Content-Type: application/json" \
		-d '{
			"user_id": "test-user-$(date +%s)",
			"event_type": "purchase",
			"timestamp": "$(date -u +"%Y-%m-%dT%H:%M:%SZ")",
			"payload": {"item_id": "test_item", "amount": 99.99, "currency": "USD"},
			"priority": 9
		}' && echo "\nMessage sent!"