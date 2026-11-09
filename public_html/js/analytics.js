let analyticsChartInstance = null;
let topProductsChartInstance = null;
let tankRatioChartInstance = null;
let peakHoursChartInstance = null;

function reloadAnalytics() {
    const branchElem = document.getElementById('branchSelect');
    const periodElem = document.getElementById('chartPeriod');

    const branch = branchElem ? branchElem.value : 'all';
    const periodVal = periodElem ? periodElem.value : 'week';

    // Parse period and forecast flag
    let period = 'week';
    let isForecast = false;

    if (periodVal.includes('forecast')) {
        isForecast = true;
        period = periodVal.split('_')[0]; // extracts 'week', 'month', or 'year'
    } else {
        period = periodVal;
    }

    fetch(`fetch_chart_data.php?branch_id=${branch}&period=${period}&forecast=${isForecast}`)
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                console.error("Backend error:", data.message);
                return;
            }

            // 1. Update KPI Cards
            if (data.cards) {
                if (document.getElementById('card-revenue')) document.getElementById('card-revenue').innerText = data.cards.revenue;
                if (document.getElementById('card-cylinders')) document.getElementById('card-cylinders').innerText = data.cards.cylinders;
                if (document.getElementById('card-aov')) document.getElementById('card-aov').innerText = data.cards.aov;
                if (document.getElementById('card-branches')) document.getElementById('card-branches').innerText = data.cards.branches;
            }

            // 2. Main Trend & Demand Forecast Chart (analyticsChart)
            const ctxMain = document.getElementById('analyticsChart');
            if (ctxMain) {
                if (analyticsChartInstance) analyticsChartInstance.destroy();

                const labels = data.labels || [];
                const actualValues = data.actual_values || [];
                const rawForecastValues = data.forecast_values || [];

                // Construct bridging dataset so the dashed red forecast line connects from the last actual point
                const forecastDatasetValues = [];
                const totalPoints = labels.length;
                const lastActualIdx = totalPoints - 2; // Index of the last real sales data point

                for (let i = 0; i < totalPoints; i++) {
                    if (i === lastActualIdx) {
                        // Start forecast line from the last actual point
                        forecastDatasetValues.push(actualValues[i]); 
                    } else if (i === totalPoints - 1) {
                        // End forecast line at the projected point
                        forecastDatasetValues.push(rawForecastValues[i]);
                    } else {
                        forecastDatasetValues.push(null);
                    }
                }

                // Dynamic point radii array to highlight only the target forecast marker
                const pointRadii = new Array(totalPoints).fill(0);
                if (totalPoints > 0) {
                    pointRadii[totalPoints - 1] = 7; // Highlight end point
                }

                analyticsChartInstance = new Chart(ctxMain.getContext('2d'), {
                    type: 'line',
                    data: {
                        labels: labels,
                        datasets: [
                            {
                                label: 'Actual Sales (₱)',
                                data: actualValues,
                                borderColor: '#2ecc71',
                                backgroundColor: 'rgba(46, 204, 113, 0.1)',
                                fill: true,
                                tension: 0.3,
                                pointRadius: 4,
                                pointHoverRadius: 6
                            },
                            {
                                label: 'Forecasted Demand (₱)',
                                data: forecastDatasetValues,
                                borderColor: '#e74c3c',
                                borderDash: [6, 6],
                                borderWidth: 3,
                                fill: false,
                                tension: 0.3,
                                spanGaps: true,
                                pointRadius: pointRadii,
                                pointHoverRadius: 9,
                                pointBackgroundColor: '#e74c3c',
                                pointBorderColor: '#ffffff',
                                pointBorderWidth: 2
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true
                            }
                        }
                    }
                });
            }

            // 3. Top Selling Brands Chart (topProductsChart)
            const ctxTop = document.getElementById('topProductsChart');
            if (ctxTop) {
                if (topProductsChartInstance) topProductsChartInstance.destroy();
                const topProducts = data.top_products || {};
                topProductsChartInstance = new Chart(ctxTop.getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: topProducts.labels || [],
                        datasets: [
                            {
                                label: 'Swap Tank',
                                data: topProducts.swaptank_values || [],
                                backgroundColor: '#3498db'
                            },
                            {
                                label: 'New Tank',
                                data: topProducts.new_values || [],
                                backgroundColor: '#e74c3c'
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false
                    }
                });
            }

            // 4. LPG Sale Type Ratio Chart (tankRatioChart)
            const ctxRatio = document.getElementById('tankRatioChart');
            if (ctxRatio) {
                if (tankRatioChartInstance) tankRatioChartInstance.destroy();
                const tankRatio = data.tank_ratio || { swaptank: 0, new_tank: 0 };
                tankRatioChartInstance = new Chart(ctxRatio.getContext('2d'), {
                    type: 'doughnut',
                    data: {
                        labels: ['Swap Tank', 'New Tank'],
                        datasets: [{
                            data: [tankRatio.swaptank, tankRatio.new_tank],
                            backgroundColor: ['#2ecc71', '#e67e22']
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false
                    }
                });
            }

            // 5. Peak Operating Hours Chart (peakHoursChart)
            const ctxPeak = document.getElementById('peakHoursChart');
            if (ctxPeak) {
                if (peakHoursChartInstance) peakHoursChartInstance.destroy();
                const peakHours = data.peak_hours || { labels: [], values: [] };
                peakHoursChartInstance = new Chart(ctxPeak.getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: peakHours.labels || [],
                        datasets: [{
                            label: 'Cylinders Sold',
                            data: peakHours.values || [],
                            backgroundColor: '#9b59b6'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false
                    }
                });
            }
        })
        .catch(err => console.error("Error fetching analytics data:", err));
}

document.addEventListener('DOMContentLoaded', () => {
    reloadAnalytics();
});