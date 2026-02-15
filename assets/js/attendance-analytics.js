/**
 * Modern Attendance Analytics System
 * Chart.js integration and analytics functionality
 */

// Global Chart.js configuration
Chart.defaults.font.family = "'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif";
Chart.defaults.font.size = 12;
Chart.defaults.font.weight = '500';
Chart.defaults.plugins.legend.labels.usePointStyle = true;
Chart.defaults.plugins.legend.labels.padding = 20;
Chart.defaults.elements.point.radius = 4;
Chart.defaults.elements.point.hoverRadius = 6;

// Color schemes for different data types
const chartColors = {
  primary: ['#3b82f6', '#1d4ed8', '#1e40af', '#2563eb'],
  success: ['#22c55e', '#16a34a', '#15803d', '#166534'],
  warning: ['#f59e0b', '#d97706', '#b45309', '#92400e'],
  error: ['#ef4444', '#dc2626', '#b91c1c', '#991b1b'],
  info: ['#3b82f6', '#2563eb', '#1d4ed8', '#1e40af'],
  neutral: ['#6b7280', '#4b5563', '#374151', '#1f2937']
};

// Attendance Analytics Class
class AttendanceAnalytics {
  constructor() {
    this.charts = new Map();
    this.dataCache = new Map();
  }

  /**
   * Initialize analytics dashboard
   */
  init() {
    this.createAttendanceOverviewChart();
    this.createTrendChart();
    this.createClassComparisonChart();
    this.createStudentRiskChart();
    this.updateLiveStats();
    this.setupAutoRefresh();
  }

  /**
   * Create attendance overview pie chart
   */
  createAttendanceOverviewChart() {
    const ctx = document.getElementById('attendance-overview-chart');
    if (!ctx) return;

    const data = this.getAttendanceOverviewData();

    const chart = new Chart(ctx, {
      type: 'doughnut',
      data: {
        labels: ['Present', 'Absent', 'Late', 'Leave'],
        datasets: [{
          data: [data.present, data.absent, data.late, data.leave],
          backgroundColor: [
            chartColors.success[0],
            chartColors.error[0],
            chartColors.warning[0],
            chartColors.info[0]
          ],
          borderWidth: 0,
          hoverBorderWidth: 2,
          hoverBorderColor: '#ffffff'
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            position: 'bottom',
            labels: {
              padding: 20,
              usePointStyle: true
            }
          },
          tooltip: {
            callbacks: {
              label: function(context) {
                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                const percentage = ((context.parsed / total) * 100).toFixed(1);
                return `${context.label}: ${context.parsed} (${percentage}%)`;
              }
            }
          }
        },
        cutout: '70%'
      }
    });

    this.charts.set('overview', chart);
  }

  /**
   * Create 7-day attendance trend chart
   */
  createTrendChart() {
    const ctx = document.getElementById('attendance-trend-chart');
    if (!ctx) return;

    const data = this.getTrendData();

    const chart = new Chart(ctx, {
      type: 'line',
      data: {
        labels: data.labels,
        datasets: [{
          label: 'Attendance Rate (%)',
          data: data.rates,
          borderColor: chartColors.primary[0],
          backgroundColor: chartColors.primary[0] + '20',
          fill: true,
          tension: 0.4,
          pointBackgroundColor: chartColors.primary[0],
          pointBorderColor: '#ffffff',
          pointBorderWidth: 2
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            display: false
          },
          tooltip: {
            mode: 'index',
            intersect: false,
            callbacks: {
              label: function(context) {
                return `Attendance: ${context.parsed.y}%`;
              }
            }
          }
        },
        scales: {
          y: {
            beginAtZero: true,
            max: 100,
            ticks: {
              callback: function(value) {
                return value + '%';
              }
            }
          },
          x: {
            grid: {
              display: false
            }
          }
        },
        interaction: {
          intersect: false,
          mode: 'index'
        }
      }
    });

    this.charts.set('trend', chart);
  }

  /**
   * Create class comparison bar chart
   */
  createClassComparisonChart() {
    const ctx = document.getElementById('class-comparison-chart');
    if (!ctx) return;

    const data = this.getClassComparisonData();

    const chart = new Chart(ctx, {
      type: 'bar',
      data: {
        labels: data.labels,
        datasets: [{
          label: 'Attendance Rate (%)',
          data: data.rates,
          backgroundColor: chartColors.primary.map(color => color + '80'),
          borderColor: chartColors.primary,
          borderWidth: 1,
          borderRadius: 4,
          borderSkipped: false
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            display: false
          },
          tooltip: {
            callbacks: {
              label: function(context) {
                return `Attendance: ${context.parsed.y}%`;
              }
            }
          }
        },
        scales: {
          y: {
            beginAtZero: true,
            max: 100,
            ticks: {
              callback: function(value) {
                return value + '%';
              }
            }
          },
          x: {
            grid: {
              display: false
            }
          }
        }
      }
    });

    this.charts.set('comparison', chart);
  }

  /**
   * Create student risk assessment chart
   */
  createStudentRiskChart() {
    const ctx = document.getElementById('student-risk-chart');
    if (!ctx) return;

    const data = this.getStudentRiskData();

    const chart = new Chart(ctx, {
      type: 'horizontalBar',
      data: {
        labels: data.labels,
        datasets: [{
          label: 'Attendance Rate (%)',
          data: data.rates,
          backgroundColor: data.colors,
          borderWidth: 0,
          borderRadius: 4,
          borderSkipped: false
        }]
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            display: false
          },
          tooltip: {
            callbacks: {
              label: function(context) {
                return `Attendance: ${context.parsed.x}%`;
              }
            }
          }
        },
        scales: {
          x: {
            beginAtZero: true,
            max: 100,
            ticks: {
              callback: function(value) {
                return value + '%';
              }
            }
          },
          y: {
            grid: {
              display: false
            }
          }
        }
      }
    });

    this.charts.set('risk', chart);
  }

  /**
   * Update live statistics with animations
   */
  updateLiveStats() {
    const stats = this.getLiveStats();

    // Animate counters
    this.animateCounter('total-students', stats.totalStudents);
    this.animateCounter('present-count', stats.present);
    this.animateCounter('absent-count', stats.absent);
    this.animateCounter('late-count', stats.late);
    this.animateCounter('attendance-rate', stats.rate, '%');
  }

  /**
   * Animate counter values
   */
  animateCounter(elementId, targetValue, suffix = '') {
    const element = document.getElementById(elementId);
    if (!element) return;

    const startValue = parseFloat(element.textContent.replace(/[^\d.]/g, '')) || 0;
    const duration = 1000;
    const startTime = performance.now();

    const animate = (currentTime) => {
      const elapsed = currentTime - startTime;
      const progress = Math.min(elapsed / duration, 1);

      // Easing function
      const easeOut = 1 - Math.pow(1 - progress, 3);
      const currentValue = startValue + (targetValue - startValue) * easeOut;

      element.textContent = Math.round(currentValue) + suffix;

      if (progress < 1) {
        requestAnimationFrame(animate);
      }
    };

    requestAnimationFrame(animate);
  }

  /**
   * Setup auto-refresh for live data
   */
  setupAutoRefresh() {
    setInterval(() => {
      this.updateLiveStats();
      this.refreshCharts();
    }, 30000); // Refresh every 30 seconds
  }

  /**
   * Refresh all charts with new data
   */
  refreshCharts() {
    this.charts.forEach((chart, name) => {
      if (name === 'overview') {
        const data = this.getAttendanceOverviewData();
        chart.data.datasets[0].data = [data.present, data.absent, data.late, data.leave];
      } else if (name === 'trend') {
        const data = this.getTrendData();
        chart.data.labels = data.labels;
        chart.data.datasets[0].data = data.rates;
      }
      // Add other chart refresh logic as needed
      chart.update('active');
    });
  }

  /**
   * Mock data methods - replace with actual API calls
   */
  getAttendanceOverviewData() {
    return {
      present: 85,
      absent: 10,
      late: 3,
      leave: 2
    };
  }

  getTrendData() {
    const days = 7;
    const labels = [];
    const rates = [];

    for (let i = days - 1; i >= 0; i--) {
      const date = new Date();
      date.setDate(date.getDate() - i);
      labels.push(date.toLocaleDateString('en-US', { weekday: 'short' }));

      // Generate realistic attendance rates
      const baseRate = 85;
      const variation = Math.sin(i / 2) * 5 + Math.random() * 5;
      rates.push(Math.max(0, Math.min(100, baseRate + variation)));
    }

    return { labels, rates };
  }

  getClassComparisonData() {
    return {
      labels: ['Class A', 'Class B', 'Class C', 'Class D', 'Class E'],
      rates: [92, 88, 85, 90, 87]
    };
  }

  getStudentRiskData() {
    return {
      labels: ['John Doe', 'Jane Smith', 'Bob Wilson', 'Alice Brown', 'Charlie Davis'],
      rates: [65, 72, 78, 85, 92],
      colors: [
        chartColors.error[0] + '80',
        chartColors.warning[0] + '80',
        chartColors.warning[0] + '80',
        chartColors.success[0] + '80',
        chartColors.success[0] + '80'
      ]
    };
  }

  getLiveStats() {
    return {
      totalStudents: 45,
      present: 38,
      absent: 5,
      late: 2,
      rate: 84.4
    };
  }

  /**
   * Export chart as image
   */
  exportChart(chartName, format = 'png') {
    const chart = this.charts.get(chartName);
    if (!chart) return null;

    return chart.toBase64Image();
  }

  /**
   * Destroy all charts
   */
  destroy() {
    this.charts.forEach(chart => chart.destroy());
    this.charts.clear();
    this.dataCache.clear();
  }
}

// Initialize analytics when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
  window.attendanceAnalytics = new AttendanceAnalytics();
  window.attendanceAnalytics.init();
});

// Utility functions for attendance management
const AttendanceUtils = {
  /**
   * Format attendance percentage
   */
  formatPercentage(value, decimals = 1) {
    return `${parseFloat(value).toFixed(decimals)}%`;
  },

  /**
   * Get status color class
   */
  getStatusClass(status) {
    const classes = {
      'present': 'status-present',
      'absent': 'status-absent',
      'late': 'status-late',
      'leave': 'status-leave'
    };
    return classes[status] || 'status-leave';
  },

  /**
   * Calculate attendance rate
   */
  calculateRate(present, total) {
    if (total === 0) return 0;
    return (present / total) * 100;
  },

  /**
   * Get risk level based on attendance rate
   */
  getRiskLevel(rate) {
    if (rate >= 90) return 'excellent';
    if (rate >= 80) return 'good';
    if (rate >= 70) return 'warning';
    return 'critical';
  },

  /**
   * Format date for display
   */
  formatDate(date, format = 'short') {
    const options = format === 'short'
      ? { month: 'short', day: 'numeric' }
      : { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };

    return new Date(date).toLocaleDateString('en-US', options);
  }
};

// Export utilities
window.AttendanceUtils = AttendanceUtils;
