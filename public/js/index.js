import Alpine from "alpinejs";
import focus from "@alpinejs/focus";
import uPlot from "uplot";

window.Alpine = Alpine;

Alpine.plugin(focus);

window.createTrafficChart = function (element, options) {
    let chart = null;
    let data = [[], [], []];
    let windowEnd = Date.now();
    let windowMs = 60000;

    const color = (light, dark) => document.documentElement.classList.contains('dark') ? dark : light;
    const formatMinute = value => new Date(value * 1000).toLocaleTimeString(
        document.documentElement.lang,
        { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }
    );
    const setFollowingScale = () => {
        if (!chart || data[0].length < 2) {
            return;
        }

        chart.setScale('x', {
            min: (windowEnd - windowMs) / 1000,
            max: windowEnd / 1000
        });
    };
    const create = () => {
        if (chart || data[0].length < 2) {
            return;
        }

        chart = new uPlot({
            width: Math.max(1, element.clientWidth),
            height: Math.max(1, element.clientHeight),
            padding: [8, 10, 0, 0],
            legend: { show: false },
            cursor: {
                drag: { x: false, y: false, setScale: false },
                points: { size: 7 }
            },
            select: { show: false },
            series: [
                {},
                {
                    label: options.rxLabel,
                    stroke: () => '#14b8a6',
                    width: 2.5,
                    cap: 'round',
                    paths: uPlot.paths.spline(),
                    points: { show: false }
                },
                {
                    label: options.txLabel,
                    stroke: () => '#8b5cf6',
                    width: 2.5,
                    dash: [8, 6],
                    cap: 'round',
                    paths: uPlot.paths.spline(),
                    points: { show: false }
                }
            ],
            axes: [
                {
                    size: 30,
                    gap: 7,
                    space: 120,
                    stroke: () => color('#71717a', '#a1a1aa'),
                    grid: { stroke: () => color('#e4e4e7', '#3f3f46'), width: 1 },
                    ticks: { show: false },
                    values: (u, ticks) => {
                        let previous = '';
                        return ticks.map(value => {
                            const label = formatMinute(value);
                            if (label === previous) {
                                return '';
                            }
                            previous = label;
                            return label;
                        });
                    }
                },
                {
                    side: 3,
                    size: 68,
                    gap: 8,
                    stroke: () => color('#71717a', '#a1a1aa'),
                    grid: { stroke: () => color('#e4e4e7', '#3f3f46'), width: 1 },
                    ticks: { show: false },
                    values: (u, ticks) => ticks.map(options.formatBitRate)
                }
            ],
            scales: {
                y: {
                    range: (u, min, max) => [0, Math.max(1, max * 1.08)]
                }
            },
            hooks: {
                drawClear: [u => {
                    u.ctx.lineCap = 'round';
                    u.ctx.lineJoin = 'round';
                }],
                setCursor: [u => {
                    const index = u.cursor.idx;
                    options.onHover(index == null ? null : {
                        at: data[0][index] * 1000,
                        rx: data[1][index],
                        tx: data[2][index]
                    });
                }]
            }
        }, data, element);

        setFollowingScale();
    };

    const resizeObserver = new ResizeObserver(entries => {
        if (!chart) {
            return;
        }

        const rect = entries[0].contentRect;
        chart.setSize({ width: Math.max(1, rect.width), height: Math.max(1, rect.height) });
    });
    resizeObserver.observe(element);

    const themeObserver = new MutationObserver(() => {
        if (chart) {
            chart.redraw(false, true);
        }
    });
    themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });

    return {
        update(samples, end, duration) {
            data = [
                samples.map(sample => sample.at / 1000),
                samples.map(sample => sample.rx),
                samples.map(sample => sample.tx)
            ];
            windowEnd = end;
            windowMs = duration;

            create();
            if (!chart) {
                return;
            }

            chart.setData(data, true);
            setFollowingScale();
        },
        clear() {
            data = [[], [], []];
            options.onHover(null);
            if (chart) {
                chart.destroy();
                chart = null;
            }
        },
        destroy() {
            resizeObserver.disconnect();
            themeObserver.disconnect();
            if (chart) {
                chart.destroy();
            }
        }
    };
};

Alpine.start();

window.exportBlob = function (content, filename, mime) {
    const blob = new Blob([content], { type: mime + ';charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
};
