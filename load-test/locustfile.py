from locust import HttpUser, task, between, events
import uuid
import json
import time
from datetime import datetime

class EventServiceUser(HttpUser):
    wait_time = between(0.1, 0.5)  # 100-500ms между запросами

    def on_start(self):
        """Вызывается при старте каждого пользователя"""
        self.user_id = str(uuid.uuid4())
        self.event_types = ['click', 'view', 'purchase', 'login', 'signup']
        self.priorities = [1, 5, 9]

    @task(5)  # 5x чаще
    def send_low_priority_event(self):
        """Отправка low priority события (клики, просмотры)"""
        self.send_event('click', 1)

    @task(3)  # 3x чаще
    def send_medium_priority_event(self):
        """Отправка medium priority события"""
        self.send_event('login', 5)

    @task(1)  # 1x реже
    def send_high_priority_event(self):
        """Отправка high priority события (покупки)"""
        self.send_event('purchase', 9, amount=99.99)

    def send_event(self, event_type, priority, amount=None):
        """Общий метод отправки события"""
        payload = {
            "user_id": self.user_id,
            "event_type": event_type,
            "timestamp": datetime.utcnow().isoformat() + "Z",
            "payload": {},
            "priority": priority,
            "metadata": {
                "app_version": "2.1.0",
                "platform": "web",
                "load_test": True
            }
        }

        if event_type == 'purchase' and amount:
            payload["payload"] = {
                "item_id": f"item_{uuid.uuid4().hex[:8]}",
                "amount": amount,
                "currency": "USD"
            }
        elif event_type == 'click':
            payload["payload"] = {
                "button": "buy_now",
                "page": "/products/123"
            }

        headers = {
            "Content-Type": "application/json",
            "X-Load-Test": "true"
        }

        with self.client.post(
            "/api/v1/events",
            json=payload,
            headers=headers,
            catch_response=True
        ) as response:
            if response.status_code == 202:
                response.success()
            else:
                response.failure(f"Status: {response.status_code}")

    @events.request.add_listener
    def on_request(request_type, name, response_time, response_length, exception, context, **kwargs):
        """Кастомная метрика для мониторинга"""
        if exception:
            print(f"Request failed: {name}, Exception: {exception}")

@events.test_start.add_listener
def on_test_start(environment, **kwargs):
    print("🚀 Starting load test...")

@events.test_stop.add_listener
def on_test_stop(environment, **kwargs):
    print("🏁 Load test finished")