# Основные команды
.PHONY: up down build logs worker shell migrate mysql fresh test

# Мониторинг
.PHONY: metrics monitor prometheus grafana logs-metrics

# RabbitMQ
.PHONY: rabbitmq rabbitmq-ui rabbitmq-logs rabbitmq-stats rabbitmq-test
.PHONY: rabbitmq-workers rabbitmq-worker-logs rabbitmq-worker-restart rabbitmq-worker-status

# Redis Workers
.PHONY: redis-workers redis-worker-logs redis-worker-restart redis-worker-status

# DLQ / CB / Failover
.PHONY: dlq-stats dlq-restore dlq-cleanup circuit-breaker-stats failover-test

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

shell:
	./vendor/bin/sail exec app bash

migrate:
	./vendor/bin/sail exec app php artisan migrate

mysql:
	./vendor/bin/sail mysql

fresh:
	./vendor/bin/sail exec app php artisan migrate:fresh --seed

test:
	./vendor/bin/sail exec app php artisan test

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
	@echo "NOT IMPLEMENTED: Testing RabbitMQ connection..."
	./vendor/bin/sail exec app php artisan rabbitmq:test

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
	@UUID=$$(cat /dev/urandom | tr -dc 'a-f0-9' | fold -w 32 | head -n 1 | sed 's/\(........\)\(....\)\(....\)\(....\)\(............\)/\1-\2-\3-\4-\5/'); \
	curl -X POST http://localhost/api/v1/events \
		-H "Content-Type: application/json" \
		-d "{\
			\"user_id\": \"$$UUID\",\
			\"event_type\": \"purchase\",\
			\"timestamp\": \"$$(date -u +'%Y-%m-%dT%H:%M:%SZ')\",\
			\"payload\": {\"item_id\": \"test_item\", \"amount\": 99.99, \"currency\": \"USD\"},\
			\"priority\": 9\
		}" && echo "\nMessage sent!"

redis-workers:
	./vendor/bin/sail exec app supervisorctl start redis-stream-worker:*
	./vendor/bin/sail exec app supervisorctl start redis-high-stream-worker:*

redis-worker-logs:
	@echo "=== Redis Stream Workers ==="
	./vendor/bin/sail exec app tail -f storage/logs/redis-stream-worker.log
	@echo "\n=== Redis High Priority Workers ==="
	./vendor/bin/sail exec app tail -f storage/logs/redis-high-stream-worker.log

redis-worker-restart:
	./vendor/bin/sail exec app touch /tmp/restart-redis-workers
	./vendor/bin/sail exec app supervisorctl signal HUP redis-stream-worker:*
	./vendor/bin/sail exec app supervisorctl signal HUP redis-high-stream-worker:*

redis-worker-status:
	./vendor/bin/sail exec app supervisorctl status redis-stream-worker:*
	./vendor/bin/sail exec app supervisorctl status redis-high-stream-worker:*

workers-all: rabbitmq-workers redis-workers
	@echo "All workers started"

workers-status-all:
	@echo "=== RabbitMQ Workers ==="
	make rabbitmq-worker-status
	@echo "\n=== Redis Workers ==="
	make redis-worker-status

workers-restart-all:
	make rabbitmq-worker-restart
	make redis-worker-restart

dlq-stats:
	./vendor/bin/sail exec app php artisan dlq:manage stats

dlq-restore:
	./vendor/bin/sail exec app php artisan dlq:manage restore --limit=1000

dlq-cleanup:
	./vendor/bin/sail exec app php artisan dlq:manage cleanup --older-than=7

circuit-breaker-stats:
	@echo "=== Circuit Breaker Stats ==="
	curl -s http://localhost/api/v1/metrics | grep -E '(circuit_breaker|queue_failover)' | head -20

failover-test:
	@echo "Testing failover mechanism..."
	@echo "1. Stopping RabbitMQ..."
	docker compose stop rabbitmq
	sleep 3
	@echo "\n2. Sending test event..."
	curl -X POST http://localhost/api/v1/events \
		-H "Content-Type: application/json" \
		-d '{
			"user_id": "failover-test-$(date +%s)",
			"event_type": "purchase",
			"timestamp": "$(date -u +"%Y-%m-%dT%H:%M:%SZ")",
			"payload": {"item_id": "test_failover", "amount": 1.99},
			"priority": 9
		}' && echo "\nEvent sent during RabbitMQ outage!"
	@echo "\n3. Checking metrics..."
	make circuit-breaker-stats
	@echo "\n4. Restarting RabbitMQ..."
	docker compose start rabbitmq
	sleep 5
	@echo "\n5. Testing recovery..."
	curl -X POST http://localhost/api/v1/events \
		-H "Content-Type: application/json" \
		-d '{
			"user_id": "recovery-test-$(date +%s)",
			"event_type": "purchase",
			"timestamp": "$(date -u +"%Y-%m-%dT%H:%M:%SZ")",
			"payload": {"item_id": "test_recovery", "amount": 2.99},
			"priority": 9
		}' && echo "\nEvent sent after RabbitMQ recovery!"

.PHONY: alerts alertmanager grafana-dashboards load-test load-test-report perf-test

alerts:
	@echo "=== Prometheus Alerts ==="
	curl -s http://localhost:9090/api/v1/alerts | jq '.data.alerts[] | {state: .state, name: .labels.alertname, severity: .labels.severity}'

alertmanager:
	@echo "Alertmanager UI: http://localhost:9093"
	open http://localhost:9093 || xdg-open http://localhost:9093

grafana-dashboards:
	@echo "Importing Grafana dashboards..."
	docker-compose exec grafana grafana-cli plugins install grafana-piechart-panel
	docker-compose restart grafana
	sleep 5
	@echo "Dashboards available at: http://localhost:3000"
	open http://localhost:3000 || xdg-open http://localhost:3000

load-test:
	@echo "Installing dependencies..."
	pip install -r load-test/requirements.txt 2>/dev/null || echo "Install manually: pip install locust pandas requests"
	@echo "Running load test..."
	cd load-test && python load_test.py

load-test-report:
	@echo "Load test report: file://$(PWD)/load-test/results/report.html"
	open load-test/results/report.html || xdg-open load-test/results/report.html

perf-test:
	@echo "=== Performance Test Suite ==="
	@echo "1. Baseline metrics..."
	curl -s http://localhost/api/v1/metrics | grep -E '(events_processed_total|event_processing_duration_seconds)' | head -5
	@echo "\n2. Starting load test..."
	make load-test
	@echo "\n3. Post-test metrics..."
	curl -s http://localhost/api/v1/metrics | grep -E '(events_processed_total|event_processing_duration_seconds)' | head -5
	@echo "\n4. Queue status..."
	curl -s http://localhost/api/v1/system/queue-stats | jq .

test-alert:
	@echo "Testing alert system..."
	@echo "1. Creating high queue backlog..."
	# Генерируем много событий
	for i in {1..1000}; do
		curl -s -X POST http://localhost/api/v1/events \
			-H "Content-Type: application/json" \
			-d '{"user_id":"alert-test-'$$i'","event_type":"click","timestamp":"'$$(date -u +"%Y-%m-%dT%H:%M:%SZ")'","payload":{},"priority":1}' > /dev/null &
	done
	@echo "\n2. Waiting for alert..."
	sleep 30
	@echo "\n3. Checking alerts..."
	make alerts

monitor-all:
	@echo "=== Monitoring Dashboard ==="
	@echo "Prometheus: http://localhost:9090"
	@echo "Alertmanager: http://localhost:9093"
	@echo "Grafana: http://localhost:3000 (admin/admin)"
	@echo "RabbitMQ: http://localhost:15672 (admin/admin123)"
	@echo "\nQuick check:"
	curl -s http://localhost/api/v1/health | jq .