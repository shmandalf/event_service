.PHONY: up down test logs worker shell migrate fresh metrics monitor prometheus grafana logs-metrics rabbitmq rabbitmq-ui rabbitmq-logs rabbitmq-stats rabbitmq-test rabbitmq-workers rabbitmq-worker-logs rabbitmq-worker-restart rabbitmq-worker-status

up:
	./vendor/bin/sail up -d
	@echo "Services started"
	@echo "API: http://localhost:80"
	@echo "Prometheus: http://localhost:9090"
	@echo "Grafana: http://localhost:3000 (admin/admin)"

down:
	./vendor/bin/sail down -v

build:
	./vendor/bin/sail build

logs:
	./vendor/bin/sail logs -f app

worker:
	./vendor/bin/sail exec app php artisan queue:work --queue=high,default --sleep=3 --tries=3 --timeout=60

worker-stream:
	./vendor/bin/sail exec app php artisan events:process

shell:
	./vendor/bin/sail exec app bash

migrate:
	./vendor/bin/sail exec app php artisan migrate

fresh:
	./vendor/bin/sail exec app php artisan migrate:fresh --seed

test:
	./vendor/bin/sail exec app php artisan test

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
	./vendor/bin/sail logs --tail=50 prometheus grafana redis-exporter

rabbitmq:
	@echo "RabbitMQ Management UI: http://localhost:15672"
	@echo "Username: admin"
	@echo "Password: admin123"
	open http://localhost:15672 || xdg-open http://localhost:15672

rabbitmq-ui:
	./vendor/bin/sail exec rabbitmq rabbitmqadmin list queues name messages messages_ready messages_unacknowledged

rabbitmq-logs:
	./vendor/bin/sail logs --tail=100 rabbitmq

rabbitmq-stats:
	@echo "=== RabbitMQ Queue Stats ==="
	curl -s -u admin:admin123 http://localhost:15672/api/queues | jq '.[] | {name: .name, messages: .messages, messages_ready: .messages_ready, state: .state}'

rabbitmq-test:
	@echo "Testing RabbitMQ connection..."
	./vendor/bin/sail exec app php artisan rabbitmq:test

rabbitmq-consume:
	./vendor/bin/sail exec app php artisan rabbitmq:consume high_priority

rabbitmq-workers:
	./vendor/bin/sail exec app supervisorctl start rabbitmq-high-worker:*
	./vendor/bin/sail exec app supervisorctl start rabbitmq-normal-worker:*

rabbitmq-worker-logs:
	@echo "=== High Priority Workers ==="
	./vendor/bin/sail exec app tail -f storage/logs/rabbitmq-high-worker.log
	@echo "\n=== Normal Workers ==="
	./vendor/bin/sail exec app tail -f storage/logs/rabbitmq-normal-worker.log

rabbitmq-worker-restart:
	./vendor/bin/sail exec app touch /tmp/restart-workers
	./vendor/bin/sail exec app supervisorctl signal HUP rabbitmq-high-worker:*
	./vendor/bin/sail exec app supervisorctl signal HUP rabbitmq-normal-worker:*

rabbitmq-worker-status:
	./vendor/bin/sail exec app supervisorctl status rabbitmq-high-worker:*
	./vendor/bin/sail exec app supervisorctl status rabbitmq-normal-worker:*

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