#!/usr/bin/env python3
"""
Нагрузочный тест Event Service
"""
import subprocess
import time
import requests
import json
import sys
from pathlib import Path

def run_locust(users=100, spawn_rate=10, run_time="1m"):
    """Запуск Locust"""
    print(f"🧪 Starting load test: {users} users, {spawn_rate}/s spawn rate, {run_time} duration")

    cmd = [
        "locust",
        "-f", "locustfile.py",
        "--headless",
        "--users", str(users),
        "--spawn-rate", str(spawn_rate),
        "--run-time", run_time,
        "--host", "http://localhost:80",
        "--csv", "load-test/results/load_test",
        "--html", "load-test/results/report.html",
        "--logfile", "load-test/results/locust.log"
    ]

    try:
        result = subprocess.run(cmd, capture_output=True, text=True)
        print("STDOUT:", result.stdout)
        if result.stderr:
            print("STDERR:", result.stderr)

        return result.returncode == 0
    except FileNotFoundError:
        print("❌ Locust не установлен. Установите: pip install locust")
        return False

def check_health():
    """Проверка здоровья сервиса перед тестом"""
    print("🔍 Checking service health...")

    try:
        response = requests.get("http://localhost/api/v1/health", timeout=5)
        if response.status_code == 200:
            health = response.json()
            print(f"✅ Health status: {health['status']}")
            return True
        else:
            print(f"❌ Health check failed: {response.status_code}")
            return False
    except requests.exceptions.RequestException as e:
        print(f"❌ Health check error: {e}")
        return False

def get_metrics():
    """Получение метрик до и после теста"""
    try:
        response = requests.get("http://localhost/api/v1/metrics", timeout=5)
        if response.status_code == 200:
            return response.text
    except:
        pass
    return ""

def analyze_results():
    """Анализ результатов теста"""
    results_dir = Path("load-test/results")

    # Чтение CSV результатов
    stats_file = results_dir / "load_test_stats.csv"
    if stats_file.exists():
        import pandas as pd
        df = pd.read_csv(stats_file)

        print("\n📊 Test Results Summary:")
        print("=" * 50)

        total_requests = df[df['Name'] == 'Aggregated']['Request Count'].values[0]
        failure_rate = df[df['Name'] == 'Aggregated']['Failure Rate'].values[0]
        avg_response_time = df[df['Name'] == 'Aggregated']['Median Response Time'].values[0]
        rps = df[df['Name'] == 'Aggregated']['Requests/s'].values[0]

        print(f"Total Requests: {total_requests}")
        print(f"Failure Rate: {failure_rate:.2%}")
        print(f"Median Response Time: {avg_response_time:.0f}ms")
        print(f"Requests per Second: {rps:.1f}")

        # Проверка SLA
        if failure_rate > 0.01:  # 1%
            print("❌ SLA Violation: Failure rate > 1%")
            return False
        if avg_response_time > 500:  # 500ms
            print("❌ SLA Violation: Response time > 500ms")
            return False

        print("✅ All SLA requirements met")
        return True

    return False

def run_performance_test():
    """Запуск серии тестов с разной нагрузкой"""
    print("🎯 Starting performance test suite")
    print("=" * 60)

    test_scenarios = [
        {"name": "Low Load", "users": 50, "spawn_rate": 5, "duration": "30s"},
        {"name": "Medium Load", "users": 200, "spawn_rate": 20, "duration": "1m"},
        {"name": "High Load", "users": 500, "spawn_rate": 50, "duration": "2m"},
        {"name": "Peak Load", "users": 1000, "spawn_rate": 100, "duration": "30s"},
    ]

    all_passed = True

    for scenario in test_scenarios:
        print(f"\n🏃 Running {scenario['name']} test...")
        print(f"  Users: {scenario['users']}, Spawn rate: {scenario['spawn_rate']}/s")

        # Получаем метрики до теста
        metrics_before = get_metrics()

        # Запускаем тест
        if run_locust(
            users=scenario['users'],
            spawn_rate=scenario['spawn_rate'],
            run_time=scenario['duration']
        ):
            print(f"✅ {scenario['name']} test completed")

            # Анализируем результаты
            if not analyze_results():
                all_passed = False

            # Ждем стабилизации системы
            print("⏳ Waiting for system stabilization...")
            time.sleep(10)
        else:
            print(f"❌ {scenario['name']} test failed")
            all_passed = False

    return all_passed

def main():
    """Основная функция"""
    print("🚀 Event Service Load Test Suite")
    print("=" * 60)

    # Создаем директории
    Path("load-test/results").mkdir(parents=True, exist_ok=True)

    # Проверяем здоровье системы
    if not check_health():
        print("❌ Service is not healthy. Exiting.")
        sys.exit(1)

    # Запускаем тесты
    success = run_performance_test()

    # Генерация отчета
    print("\n📋 Generating final report...")

    report = {
        "timestamp": time.strftime("%Y-%m-%d %H:%M:%S"),
        "success": success,
        "test_scenarios": [
            {"name": "Low Load", "target": "50 users, 5/s spawn"},
            {"name": "Medium Load", "target": "200 users, 20/s spawn"},
            {"name": "High Load", "target": "500 users, 50/s spawn"},
            {"name": "Peak Load", "target": "1000 users, 100/s spawn"},
        ]
    }

    with open("load-test/results/final_report.json", "w") as f:
        json.dump(report, f, indent=2)

    if success:
        print("\n🎉 All tests passed!")
        print("📈 Check reports in load-test/results/")
    else:
        print("\n❌ Some tests failed")
        sys.exit(1)

if __name__ == "__main__":
    main()