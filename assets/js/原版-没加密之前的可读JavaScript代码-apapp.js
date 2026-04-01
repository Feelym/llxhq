// 域名验证
(function() {
    const allowedDomains = ['zyooo.com', '145555.xyz', 'localhost', '127.0.0.1'];
    const currentHost = window.location.hostname.toLowerCase();

    const isAllowed = allowedDomains.some(domain => currentHost.includes(domain));

    if (!isAllowed) {
        document.body.innerHTML = `
            <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;height:100vh;background:#1a1a2e;color:#fff;font-family:sans-serif;text-align:center;padding:20px;">
                <h1 style="color:#ff4757;margin-bottom:20px;">⚠️ 警告</h1>
                <p style="font-size:1.2rem;margin-bottom:10px;">不要盗用代码，都是AI写的，你自己去写。</p>
                <p style="color:#4a7dff;font-size:1.1rem;">shua.zyooo.com</p>
                <p style="margin-top:20px;color:#666;">页面将在 <span id="countdown">5</span> 秒后关闭...</p>
            </div>
        `;

        let seconds = 5;
        const timer = setInterval(() => {
            seconds--;
            const countdownEl = document.getElementById('countdown');
            if (countdownEl) countdownEl.textContent = seconds;

            if (seconds <= 0) {
                clearInterval(timer);
                window.close();
                // 如果无法关闭，跳转到正版网站
                window.location.href = 'https://shua.ssooo.cn';
            }
        }, 1000);

        throw new Error('Unauthorized domain');
    }
})();

// 配置变量（从API加载）
let MAX_THREADS = 32;
let DEFAULT_THREADS = 1;
let SITE_TITLE = '流量消耗器';

// API 地址
const API_URL = 'api.php';

// 本地存储键名
const STORAGE_PREFIX = 'shua_';
const STORAGE_KEYS = {
    source: STORAGE_PREFIX + 'source',
    customLink: STORAGE_PREFIX + 'customLink',
    threads: STORAGE_PREFIX + 'threads',
    limit: STORAGE_PREFIX + 'limit',
    darkMode: STORAGE_PREFIX + 'darkMode'
};

const COOKIE_MAX_AGE = 60 * 60 * 24 * 365;

// 状态变量
let isRunning = false;
let totalBytes = 0;
let lastBytes = 0;
let lastTime = Date.now();
let speedUpdateInterval = null;
let activeRequests = [];
let limitBytes = 0;
let isRestoringSettings = false;

// 图表相关变量
let chart = null;
let chartData = {
    time: [],
    speed: [],
    latency: []
};
const MAX_DATA_POINTS = 60;
let startTimeStamp = 0;
let pingLatency = 0;

// ==================== API 功能 ====================

// 从API加载初始化数据
async function loadInitData() {
    try {
        const response = await fetch(API_URL + '?action=init');
        const result = await response.json();

        if (result.success) {
            const { settings, sources } = result.data;

            // 设置配置
            MAX_THREADS = parseInt(settings.max_threads) || 32;
            DEFAULT_THREADS = parseInt(settings.default_threads) || 1;
            SITE_TITLE = settings.site_title || '流量消耗器';

            // 更新页面元素
            document.title = SITE_TITLE;
            document.getElementById('siteTitle').textContent = SITE_TITLE;
            document.getElementById('maxThreadsLabel').textContent = MAX_THREADS;
            document.getElementById('threadCount').max = MAX_THREADS;
            document.getElementById('threadCount').value = DEFAULT_THREADS;

            // 填充测速源选择框
            populateSources(sources);

            // 加载用户设置（在API数据加载后）
            loadUserSettings();

            console.log('Init data loaded:', { MAX_THREADS, DEFAULT_THREADS, SITE_TITLE });
        } else {
            throw new Error(result.message || '加载失败');
        }
    } catch (e) {
        console.error('Load init data error:', e);
        showToast('error', '加载失败', '无法加载配置数据，请刷新重试');
        // 使用默认值填充
        populateDefaultSources();
        loadUserSettings();
    }
}

// 填充测速源选择框
function populateSources(groupedSources) {
    const select = document.getElementById('sourceSelect');
    select.innerHTML = '<option value="">-- 请选择测速源 --</option>';

    // 按分组添加选项
    for (const [groupName, sources] of Object.entries(groupedSources)) {
        const optgroup = document.createElement('optgroup');
        optgroup.label = groupName;

        sources.forEach(source => {
            const option = document.createElement('option');
            option.value = source.url;
            option.textContent = source.name;
            optgroup.appendChild(option);
        });

        select.appendChild(optgroup);
    }

    // 添加自定义选项
    const customGroup = document.createElement('optgroup');
    customGroup.label = '自定义';
    const customOption = document.createElement('option');
    customOption.value = 'custom';
    customOption.textContent = '自定义链接...';
    customGroup.appendChild(customOption);
    select.appendChild(customGroup);
}

// 默认测速源（API加载失败时使用）
function populateDefaultSources() {
    const select = document.getElementById('sourceSelect');
    select.innerHTML = `
        <option value="">-- 请选择测速源 --</option>
        <optgroup label="自定义">
            <option value="custom">自定义链接...</option>
        </optgroup>
    `;
}

// ==================== 本地存储 ====================

function setCookie(name, value, maxAge = COOKIE_MAX_AGE) {
    try {
        document.cookie = `${encodeURIComponent(name)}=${encodeURIComponent(value)}; path=/; max-age=${maxAge}; SameSite=Lax`;
    } catch (e) {
        console.error('Set cookie error:', e);
    }
}

function getCookie(name) {
    try {
        const key = encodeURIComponent(name) + '=';
        const cookie = document.cookie
            .split(';')
            .map(v => v.trim())
            .find(v => v.startsWith(key));
        return cookie ? decodeURIComponent(cookie.slice(key.length)) : null;
    } catch (e) {
        console.error('Get cookie error:', e);
        return null;
    }
}

function setPersistedValue(key, value) {
    const normalizedValue = value == null ? '' : String(value);
    try {
        localStorage.setItem(key, normalizedValue);
    } catch (e) {
        console.error('Set localStorage error:', e);
    }
    setCookie(key, normalizedValue);
}

function getPersistedValue(key) {
    try {
        const localValue = localStorage.getItem(key);
        if (localValue !== null) return localValue;
    } catch (e) {
        console.error('Get localStorage error:', e);
    }
    return getCookie(key);
}

// 保存用户设置
function saveUserSettings() {
    if (isRestoringSettings) {
        return;
    }
    try {
        const sourceSelect = document.getElementById('sourceSelect');
        const customLink = document.getElementById('customLink');
        const threadCount = document.getElementById('threadCount');
        const limitInput = document.getElementById('limitInput');

        if (sourceSelect) setPersistedValue(STORAGE_KEYS.source, sourceSelect.value);
        if (customLink) setPersistedValue(STORAGE_KEYS.customLink, customLink.value);
        if (threadCount) setPersistedValue(STORAGE_KEYS.threads, threadCount.value);
        if (limitInput) setPersistedValue(STORAGE_KEYS.limit, limitInput.value);
    } catch (e) {
        console.error('Save settings error:', e);
    }
}

// 加载用户设置
function loadUserSettings() {
    isRestoringSettings = true;
    try {
        // 加载测速源
        const savedSource = getPersistedValue(STORAGE_KEYS.source);
        if (savedSource !== null && savedSource !== '') {
            const sourceSelect = document.getElementById('sourceSelect');
            if (sourceSelect) {
                sourceSelect.value = savedSource;
                onSourceChange(false);
            }
        }

        // 加载自定义链接
        const savedCustomLink = getPersistedValue(STORAGE_KEYS.customLink);
        if (savedCustomLink) {
            const customLinkInput = document.getElementById('customLink');
            if (customLinkInput) {
                customLinkInput.value = savedCustomLink;
            }
        }

        // 加载线程数
        const savedThreads = getPersistedValue(STORAGE_KEYS.threads);
        if (savedThreads !== null && savedThreads !== '') {
            const threads = parseInt(savedThreads, 10);
            if (!isNaN(threads) && threads >= 1 && threads <= MAX_THREADS) {
                const threadInput = document.getElementById('threadCount');
                if (threadInput) {
                    threadInput.value = threads;
                }
            } else {
                const threadInput = document.getElementById('threadCount');
                if (threadInput) {
                    threadInput.value = Math.max(1, Math.min(MAX_THREADS, DEFAULT_THREADS));
                }
            }
        }

        // 加载流量上限
        const savedLimit = getPersistedValue(STORAGE_KEYS.limit);
        if (savedLimit !== null && savedLimit !== '') {
            const limitInput = document.getElementById('limitInput');
            if (limitInput) {
                limitInput.value = savedLimit;
                const gb = parseFloat(savedLimit);
                if (!isNaN(gb) && gb > 0) {
                    limitBytes = gb * 1024 * 1024 * 1024;
                    updateProgress();
                }
            }
        }
    } catch (e) {
        console.error('Load settings error:', e);
    } finally {
        isRestoringSettings = false;
    }
}

// ==================== 图表功能 ====================

// 初始化图表
function initChart() {
    const chartDom = document.getElementById('realtimeChart');
    const isDark = document.body.classList.contains('dark-mode');

    chart = echarts.init(chartDom);

    const option = {
        backgroundColor: 'transparent',
        tooltip: {
            trigger: 'axis',
            backgroundColor: isDark ? '#16213e' : '#fff',
            borderColor: isDark ? '#2a3a5a' : '#e1e5eb',
            textStyle: {
                color: isDark ? '#eaeaea' : '#333'
            },
            formatter: function(params) {
                let html = `<div style="font-weight:600;margin-bottom:8px;">${params[0].axisValue}s</div>`;
                params.forEach(item => {
                    const color = item.seriesName === '速度' ? '#5070dd' : '#b6d634';
                    const value = item.seriesName === '速度'
                        ? formatMbpsValue(item.value)
                        : item.value + ' ms';
                    html += `<div style="display:flex;align-items:center;margin:4px 0;">
                        <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:${color};margin-right:8px;"></span>
                        <span>${item.seriesName}</span>
                        <span style="margin-left:auto;font-weight:600;">${value}</span>
                    </div>`;
                });
                return html;
            }
        },
        legend: {
            data: ['速度', '延迟'],
            top: 0,
            textStyle: {
                color: isDark ? '#a0a0a0' : '#666'
            }
        },
        grid: {
            left: '3%',
            right: '4%',
            bottom: '3%',
            top: '40px',
            containLabel: true
        },
        xAxis: {
            type: 'category',
            boundaryGap: false,
            data: [],
            axisLine: {
                lineStyle: {
                    color: isDark ? '#2a3a5a' : '#e1e5eb'
                }
            },
            axisLabel: {
                color: isDark ? '#a0a0a0' : '#666',
                formatter: '{value}s'
            }
        },
        yAxis: [
            {
                type: 'value',
                name: 'Mbps',
                position: 'left',
                axisLine: {
                    show: true,
                    lineStyle: {
                        color: '#5070dd'
                    }
                },
                axisLabel: {
                    color: isDark ? '#a0a0a0' : '#666',
                    formatter: '{value}'
                },
                splitLine: {
                    lineStyle: {
                        color: isDark ? '#2a3a5a' : '#e1e5eb',
                        type: 'dashed'
                    }
                }
            },
            {
                type: 'value',
                name: 'ms',
                position: 'right',
                axisLine: {
                    show: true,
                    lineStyle: {
                        color: '#b6d634'
                    }
                },
                axisLabel: {
                    color: isDark ? '#a0a0a0' : '#666',
                    formatter: '{value}'
                },
                splitLine: {
                    show: false
                }
            }
        ],
        series: [
            {
                name: '速度',
                type: 'line',
                smooth: true,
                symbol: 'circle',
                symbolSize: 6,
                yAxisIndex: 0,
                lineStyle: {
                    color: '#5070dd',
                    width: 2
                },
                itemStyle: {
                    color: '#5070dd'
                },
                areaStyle: {
                    color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [
                        { offset: 0, color: 'rgba(80, 112, 221, 0.3)' },
                        { offset: 1, color: 'rgba(80, 112, 221, 0.05)' }
                    ])
                },
                data: []
            },
            {
                name: '延迟',
                type: 'line',
                smooth: true,
                symbol: 'circle',
                symbolSize: 6,
                yAxisIndex: 1,
                lineStyle: {
                    color: '#b6d634',
                    width: 2
                },
                itemStyle: {
                    color: '#b6d634'
                },
                areaStyle: {
                    color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [
                        { offset: 0, color: 'rgba(182, 214, 52, 0.3)' },
                        { offset: 1, color: 'rgba(182, 214, 52, 0.05)' }
                    ])
                },
                data: []
            }
        ]
    };

    chart.setOption(option);

    window.addEventListener('resize', () => {
        chart && chart.resize();
    });
}

function formatMbpsValue(value) {
    return value.toFixed(2) + ' Mbps';
}

async function measureLatency() {
    const url = getDownloadUrl();
    if (!url) return 0;

    const start = performance.now();
    try {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 3000);

        await fetch(url + (url.includes('?') ? '&' : '?') + '_ping=' + Date.now(), {
            method: 'HEAD',
            mode: 'no-cors',
            signal: controller.signal
        });

        clearTimeout(timeoutId);
        return Math.round(performance.now() - start);
    } catch (e) {
        return 0;
    }
}

function updateChartData(speedMbps) {
    const currentTime = Math.round((Date.now() - startTimeStamp) / 1000);

    chartData.time.push(currentTime);
    chartData.speed.push(parseFloat(speedMbps.toFixed(2)));
    chartData.latency.push(pingLatency);

    if (chartData.time.length > MAX_DATA_POINTS) {
        chartData.time.shift();
        chartData.speed.shift();
        chartData.latency.shift();
    }

    if (chart) {
        chart.setOption({
            xAxis: {
                data: chartData.time
            },
            series: [
                { data: chartData.speed },
                { data: chartData.latency }
            ]
        });
    }
}

function resetChartData() {
    chartData = {
        time: [],
        speed: [],
        latency: []
    };
    startTimeStamp = Date.now();

    if (chart) {
        chart.setOption({
            xAxis: { data: [] },
            series: [
                { data: [] },
                { data: [] }
            ]
        });
    }
}

function updateChartTheme() {
    if (chart) {
        chart.dispose();
        initChart();
        if (chartData.time.length > 0) {
            chart.setOption({
                xAxis: { data: chartData.time },
                series: [
                    { data: chartData.speed },
                    { data: chartData.latency }
                ]
            });
        }
    }
}

// ==================== IP 信息 ====================

async function fetchDomesticIP() {
    try {
        const response = await fetch('https://myip.ipip.net/json');
        const data = await response.json();

        if (data.ret === 'ok') {
            const ip = data.data.ip;
            const location = data.data.location.filter(item => item).join(' ');

            document.getElementById('domesticIp').textContent = ip;
            document.getElementById('domesticLocation').textContent = location;
        } else {
            throw new Error('获取失败');
        }
    } catch (e) {
        document.getElementById('domesticIp').innerHTML = '<span class="ip-error">获取失败</span>';
    }
}

async function fetchForeignIP() {
    try {
        const response = await fetch('https://ipv4-check-perf.radar.cloudflare.com/');
        const data = await response.json();

        if (data.ip_address) {
            const ip = data.ip_address;
            const location = [data.country, data.region, data.city].filter(item => item).join(' ');

            document.getElementById('foreignIp').textContent = ip;
            document.getElementById('foreignLocation').textContent = location;

            document.getElementById('foreignIpItem').style.display = 'block';
        } else {
            throw new Error('获取失败');
        }
    } catch (e) {
        document.getElementById('foreignIpItem').style.display = 'none';
        document.getElementById('ipInfoGrid').style.gridTemplateColumns = '1fr';
    }
}

// ==================== Toast 提示 ====================

function showToast(type, title, message, duration = 3000) {
    const container = document.getElementById('toastContainer');
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;

    const icons = {
        success: '✅',
        error: '❌',
        warning: '⚠️',
        info: 'ℹ️'
    };

    toast.innerHTML = `
        <div class="toast-icon">${icons[type] || icons.info}</div>
        <div class="toast-content">
            <div class="toast-title">${title}</div>
            <div class="toast-message">${message}</div>
        </div>
    `;

    container.appendChild(toast);

    setTimeout(() => {
        toast.classList.add('hide');
        setTimeout(() => toast.remove(), 300);
    }, duration);
}

// ==================== 主题 ====================

function toggleTheme() {
    document.body.classList.toggle('dark-mode');
    const isDark = document.body.classList.contains('dark-mode');
    document.getElementById('themeIcon').textContent = isDark ? '☀️' : '🌙';
    localStorage.setItem(STORAGE_KEYS.darkMode, isDark);
    updateChartTheme();
}

function initTheme() {
    if (localStorage.getItem(STORAGE_KEYS.darkMode) === 'true') {
        document.body.classList.add('dark-mode');
        document.getElementById('themeIcon').textContent = '☀️';
    }
}

// ==================== 核心功能 ====================

function onSourceChange(shouldSave = true) {
    const select = document.getElementById('sourceSelect');
    const customGroup = document.getElementById('customLinkGroup');
    if (select.value === 'custom') {
        customGroup.classList.remove('hidden');
    } else {
        customGroup.classList.add('hidden');
    }
    if (shouldSave) {
        saveUserSettings();
    }
}

function validateThreadCount(value) {
    const num = parseInt(value);
    if (isNaN(num) || num <= 0) {
        showToast('error', '线程数错误', '线程数必须大于0');
        return false;
    }
    if (num > MAX_THREADS) {
        showToast('error', '线程数错误', `线程数不能超过 ${MAX_THREADS}`);
        return false;
    }
    return true;
}

function changeThread(delta) {
    const input = document.getElementById('threadCount');
    let value = parseInt(input.value) + delta;
    value = Math.max(1, Math.min(MAX_THREADS, value));
    input.value = value;
    saveUserSettings();
}

function formatBytes(bytes, decimals = 2) {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(decimals)) + ' ' + sizes[i];
}

function formatSpeed(bytesPerSec) {
    return formatBytes(bytesPerSec) + '/s';
}

function formatMbps(bytesPerSec) {
    const mbps = (bytesPerSec * 8) / (1024 * 1024);
    return mbps.toFixed(2) + ' Mbps';
}

function setLimit() {
    const input = document.getElementById('limitInput');
    const gb = parseFloat(input.value);
    if (gb > 0) {
        limitBytes = gb * 1024 * 1024 * 1024;
        showToast('success', '设置成功', `流量上限已设置为 ${gb} GB`);
    } else {
        limitBytes = 0;
        showToast('info', '已取消', '流量上限已取消');
    }
    updateProgress();
    saveUserSettings();
}

function updateProgress() {
    const fill = document.getElementById('progressFill');
    if (limitBytes > 0) {
        const percent = Math.min(100, (totalBytes / limitBytes) * 100);
        fill.style.width = percent + '%';
    } else {
        fill.style.width = '0%';
    }
}

function updateStats() {
    const now = Date.now();
    const timeDiff = (now - lastTime) / 1000;
    const bytesDiff = totalBytes - lastBytes;
    const speed = bytesDiff / timeDiff;
    const speedMbps = (speed * 8) / (1024 * 1024);

    document.getElementById('totalData').textContent = formatBytes(totalBytes);
    document.getElementById('currentSpeed').textContent = formatSpeed(speed);
    document.getElementById('currentMbps').textContent = formatMbps(speed);

    measureLatency().then(latency => {
        pingLatency = latency;
    });

    updateChartData(speedMbps);

    lastBytes = totalBytes;
    lastTime = now;

    updateProgress();

    if (limitBytes > 0 && totalBytes >= limitBytes) {
        stopDownload();
        showToast('success', '任务完成', '已达到流量上限，自动停止！');
    }
}

function resetStats() {
    totalBytes = 0;
    lastBytes = 0;
    pingLatency = 0;
    document.getElementById('totalData').textContent = '0 B';
    document.getElementById('currentSpeed').textContent = '0 B/s';
    document.getElementById('currentMbps').textContent = '0 Mbps';
    document.getElementById('progressFill').style.width = '0%';
    resetChartData();
    showToast('success', '已重置', '统计数据已清零');
}

function getDownloadUrl() {
    const select = document.getElementById('sourceSelect');
    if (select.value === 'custom') {
        return document.getElementById('customLink').value.trim();
    }
    return select.value;
}

async function testUrl(url) {
    return new Promise((resolve) => {
        const xhr = new XMLHttpRequest();
        xhr.open('HEAD', url + (url.includes('?') ? '&' : '?') + '_t=' + Date.now(), true);
        xhr.timeout = 5000;
        xhr.onload = () => resolve(xhr.status >= 200 && xhr.status < 400);
        xhr.onerror = () => resolve(false);
        xhr.ontimeout = () => resolve(false);
        xhr.send();
    });
}

function createDownloadRequest() {
    const url = getDownloadUrl();
    if (!url) return null;

    const xhr = new XMLHttpRequest();
    const randomUrl = url + (url.includes('?') ? '&' : '?') + '_t=' + Date.now() + Math.random();

    xhr.open('GET', randomUrl, true);
    xhr.responseType = 'arraybuffer';

    xhr.onprogress = function(e) {
        if (e.lengthComputable) {
            totalBytes += e.loaded - (xhr._lastLoaded || 0);
            xhr._lastLoaded = e.loaded;
        } else if (e.loaded) {
            totalBytes += e.loaded - (xhr._lastLoaded || 0);
            xhr._lastLoaded = e.loaded;
        }
    };

    xhr.onload = function() {
        if (isRunning) {
            const index = activeRequests.indexOf(xhr);
            if (index > -1) {
                activeRequests.splice(index, 1);
            }
            const newXhr = createDownloadRequest();
            if (newXhr) {
                activeRequests.push(newXhr);
                newXhr.send();
            }
        }
    };

    xhr.onerror = function() {
        if (isRunning) {
            setTimeout(() => {
                if (isRunning) {
                    const index = activeRequests.indexOf(xhr);
                    if (index > -1) {
                        activeRequests.splice(index, 1);
                    }
                    const newXhr = createDownloadRequest();
                    if (newXhr) {
                        activeRequests.push(newXhr);
                        newXhr.send();
                    }
                }
            }, 1000);
        }
    };

    return xhr;
}

async function startDownload() {
    const url = getDownloadUrl();
    if (!url) {
        showToast('warning', '请选择测速源', '请先选择测速源或输入自定义链接');
        return;
    }

    const threadCount = parseInt(document.getElementById('threadCount').value);
    if (!validateThreadCount(threadCount)) {
        return;
    }

    if (document.getElementById('sourceSelect').value === 'custom') {
        showToast('info', '正在测试', '正在测试链接可用性...');
        const isValid = await testUrl(url);
        if (!isValid) {
            showToast('error', '连接失败', '权限不足或浏览器安全限制');
            return;
        }
    }

    isRunning = true;
    lastBytes = totalBytes;
    lastTime = Date.now();
    startTimeStamp = Date.now();

    for (let i = 0; i < threadCount; i++) {
        const xhr = createDownloadRequest();
        if (xhr) {
            activeRequests.push(xhr);
            xhr.send();
        }
    }

    speedUpdateInterval = setInterval(updateStats, 1000);

    document.getElementById('actionBtn').textContent = '停止运行';
    document.getElementById('actionBtn').classList.remove('btn-primary');
    document.getElementById('actionBtn').classList.add('btn-danger');
    document.getElementById('statusText').textContent = '运行中... (' + threadCount + ' 线程)';
    document.getElementById('statusText').classList.remove('stopped');
    document.getElementById('statusText').classList.add('running');
    document.getElementById('statsCard').classList.add('running');

    showToast('success', '开始运行', `已启动 ${threadCount} 个下载线程`);
}

function stopDownload() {
    isRunning = false;

    activeRequests.forEach(xhr => {
        try { xhr.abort(); } catch (e) {}
    });
    activeRequests = [];

    if (speedUpdateInterval) {
        clearInterval(speedUpdateInterval);
        speedUpdateInterval = null;
    }

    document.getElementById('actionBtn').textContent = '开始运行';
    document.getElementById('actionBtn').classList.remove('btn-danger');
    document.getElementById('actionBtn').classList.add('btn-primary');
    document.getElementById('statusText').textContent = '已停止';
    document.getElementById('statusText').classList.remove('running');
    document.getElementById('statusText').classList.add('stopped');
    document.getElementById('statsCard').classList.remove('running');

    document.getElementById('currentSpeed').textContent = '0 B/s';
    document.getElementById('currentMbps').textContent = '0 Mbps';
}

function toggleAction() {
    if (isRunning) {
        stopDownload();
        showToast('info', '已停止', '流量消耗已停止');
    } else {
        startDownload();
    }
}

// ==================== 截图功能 ====================

async function takeScreenshot() {
    try {
        showToast('info', '正在生成', '正在生成截图...');

        const element = document.getElementById('captureArea');

        const isDark = document.body.classList.contains('dark-mode');
        const bgColor = isDark ? '#1a1a2e' : '#f5f7fa';
        const cardBgColor = isDark ? '#16213e' : '#ffffff';
        const textColor = isDark ? '#eaeaea' : '#333333';
        const textSecondary = isDark ? '#a0a0a0' : '#666666';
        const borderColor = isDark ? '#2a3a5a' : '#e1e5eb';
        const primaryColor = '#4a7dff';
        const successColor = '#2ed573';

        const canvas = await html2canvas(element, {
            backgroundColor: bgColor,
            scale: 2,
            useCORS: true,
            logging: false,
            onclone: function(clonedDoc) {
                const clonedElement = clonedDoc.getElementById('captureArea');

                const allElements = clonedElement.querySelectorAll('*');
                allElements.forEach(el => {
                    const style = el.style;
                    if (style) {
                        const computed = window.getComputedStyle(el);
                        if (computed.color && computed.color.includes('oklch')) {
                            style.color = textColor;
                        }
                        if (computed.backgroundColor && computed.backgroundColor.includes('oklch')) {
                            style.backgroundColor = 'transparent';
                        }
                        if (computed.borderColor && computed.borderColor.includes('oklch')) {
                            style.borderColor = borderColor;
                        }
                    }
                });

                if (clonedElement) {
                    clonedElement.style.backgroundColor = bgColor;
                    clonedElement.style.padding = '16px';
                    clonedElement.style.borderRadius = '16px';
                }

                const cards = clonedElement.querySelectorAll('.card');
                cards.forEach(card => {
                    card.style.backgroundColor = cardBgColor;
                    card.style.color = textColor;
                });

                const statItems = clonedElement.querySelectorAll('.stat-item');
                statItems.forEach(item => {
                    item.style.backgroundColor = bgColor;
                });

                const statLabels = clonedElement.querySelectorAll('.stat-label');
                statLabels.forEach(label => {
                    label.style.color = textSecondary;
                });

                const statValues = clonedElement.querySelectorAll('.stat-value');
                statValues.forEach(value => {
                    if (value.classList.contains('speed')) {
                        value.style.color = successColor;
                    } else {
                        value.style.color = primaryColor;
                    }
                });

                const ipItems = clonedElement.querySelectorAll('.ip-info-item');
                ipItems.forEach(item => {
                    item.style.backgroundColor = bgColor;
                });

                const ipLabels = clonedElement.querySelectorAll('.ip-info-label');
                ipLabels.forEach(label => {
                    label.style.color = textSecondary;
                });

                const ipValues = clonedElement.querySelectorAll('.ip-info-value');
                ipValues.forEach(value => {
                    value.style.color = textColor;
                });

                const ipLocations = clonedElement.querySelectorAll('.ip-info-location');
                ipLocations.forEach(loc => {
                    loc.style.color = textSecondary;
                });

                const cardTitles = clonedElement.querySelectorAll('.card-title');
                cardTitles.forEach(title => {
                    title.style.color = textColor;
                });

                const limitSetting = clonedElement.querySelector('.limit-setting');
                if (limitSetting) {
                    limitSetting.style.display = 'none';
                }

                const watermark = clonedElement.querySelector('.watermark');
                if (watermark) {
                    watermark.style.display = 'none';
                }

                const footer = clonedDoc.createElement('div');
                footer.style.display = 'flex';
                footer.style.justifyContent = 'space-between';
                footer.style.alignItems = 'center';
                footer.style.padding = '12px 16px';
                footer.style.fontSize = '0.85rem';
                footer.style.color = textSecondary;
                footer.style.borderTop = '1px dashed ' + borderColor;
                footer.style.marginTop = '8px';

                const now = new Date();
                const timeStr = now.getFullYear() + '/' + (now.getMonth() + 1) + '/' + now.getDate() + ' ' +
                    now.getHours().toString().padStart(2, '0') + ':' +
                    now.getMinutes().toString().padStart(2, '0') + ':' +
                    now.getSeconds().toString().padStart(2, '0');

                footer.innerHTML = '<span>' + SITE_TITLE + '</span><span>shua.ssooo.cn</span><span>' + timeStr + '</span>';
                clonedElement.appendChild(footer);

                const maskIP = (ip) => {
                    if (!ip) return ip;
                    return ip.replace(/(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})/g, '$1.$2.*.*');
                };

                const domesticIp = clonedElement.querySelector('#domesticIp');
                if (domesticIp && domesticIp.textContent) {
                    domesticIp.textContent = maskIP(domesticIp.textContent);
                }

                const foreignIp = clonedElement.querySelector('#foreignIp');
                if (foreignIp && foreignIp.textContent) {
                    foreignIp.textContent = maskIP(foreignIp.textContent);
                }
            }
        });

        canvas.toBlob(async (blob) => {
            try {
                if (navigator.clipboard && navigator.clipboard.write) {
                    const item = new ClipboardItem({ 'image/png': blob });
                    await navigator.clipboard.write([item]);
                    showToast('success', '截图成功', '图片已复制到剪贴板，可直接粘贴分享');
                } else {
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = 'screenshot_' + Date.now() + '.png';
                    a.click();
                    URL.revokeObjectURL(url);
                    showToast('success', '截图成功', '图片已下载（浏览器不支持复制到剪贴板）');
                }
            } catch (clipboardError) {
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = 'screenshot_' + Date.now() + '.png';
                a.click();
                URL.revokeObjectURL(url);
                showToast('warning', '部分成功', '无法复制到剪贴板，图片已下载');
            }
        }, 'image/png');

    } catch (error) {
        showToast('error', '截图失败', '生成截图时发生错误：' + error.message);
    }
}

// ==================== 初始化 ====================

window.onbeforeunload = function() {
    if (isRunning) {
        return '流量消耗正在运行中，确定要离开吗？';
    }
};

document.addEventListener('touchend', function(e) {
    const now = Date.now();
    if (now - (window._lastTouch || 0) < 300) {
        e.preventDefault();
    }
    window._lastTouch = now;
}, { passive: false });

document.addEventListener('DOMContentLoaded', () => {
    initTheme();
    initChart();
    fetchDomesticIP();
    fetchForeignIP();

    // 从API加载配置和测速源
    loadInitData();

    // 绑定事件
    document.getElementById('threadCount').addEventListener('change', function() {
        if (!validateThreadCount(this.value)) {
            this.value = DEFAULT_THREADS;
        }
        saveUserSettings();
    });

    document.getElementById('threadCount').addEventListener('input', function() {
        saveUserSettings();
    });

    document.getElementById('customLink').addEventListener('change', saveUserSettings);
    document.getElementById('customLink').addEventListener('input', saveUserSettings);

    document.getElementById('limitInput').addEventListener('change', saveUserSettings);
    document.getElementById('limitInput').addEventListener('input', saveUserSettings);
});
