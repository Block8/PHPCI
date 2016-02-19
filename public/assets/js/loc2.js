var locPlugin2 = ActiveBuild.UiPlugin.extend({
    id: 'build-lines-chart-structure',
    css: 'col-xs-6',
    title: Lang.get('structure'),
    lastData: null,
    displayOnUpdate: false,
    rendered: false,
    chartData: null,

    register: function() {
        var self = this;
        var query = ActiveBuild.registerQuery('phploc-structure', -1, {num_builds: 10, key: 'phploc-structure'})

        $(window).on('phploc-structure', function(data) {
            self.onUpdate(data);
        });

        $(window).on('build-updated', function(data) {
            if (data.queryData.status > 1 && !self.rendered) {
                query();
            }
        });
    },

    render: function() {
        var self = this;
        var container = $('<div id="phploc-structure" style="width: 100%; height: 300px"></div>');
        container.append('<canvas id="phploc-structure-chart" style="width: 100%; height: 300px"></canvas>');

        $(document).on('shown.bs.tab', function () {
            $('#build-lines-chart-structure').hide();
            self.drawChart();
        });

        return container;
    },

    onUpdate: function(e) {
        this.lastData = e.queryData;
        this.displayChart();
    },

    displayChart: function() {
        var self = this;
        var builds = this.lastData;
        self.rendered = true;

        self.chartData = {
            labels: [],
            datasets: [
                {
                    label: "Namespaces",
                    strokeColor: "rgba(60,141,188,1)",
                    pointColor: "rgba(60,141,188,1)",
                    data: []
                },
                {
                    label: "Interfaces",
                    strokeColor: "rgba(245,105,84,1)",
                    pointColor: "rgba(245,105,84,1)",
                    data: []
                },
                {
                    label: "Classes",
                    strokeColor: "rgba(0,166,90,1)",
                    pointColor: "rgba(0,166,90,1)",
                    data: []
                },
                {
                    label: "Methods",
                    strokeColor: "rgba(0,192,239,1)",
                    pointColor: "rgba(0,192,239,1)",
                    data: []
                }
            ]
        };

        for (var i in builds) {
            self.chartData.labels.push('Build ' + builds[i].build_id);
            self.chartData.datasets[0].data.push(builds[i].meta_value.Namespaces);
            self.chartData.datasets[1].data.push(builds[i].meta_value.Interfaces);
            self.chartData.datasets[2].data.push(builds[i].meta_value.Classes);
            self.chartData.datasets[3].data.push(builds[i].meta_value.Methods);
        }

        self.drawChart();
    },

    drawChart: function () {
        var self = this;

        if ($('#information').hasClass('active') && self.chartData && self.lastData) {
            $('#build-lines-chart-structure').show();

            var ctx = $("#phploc-structure-chart").get(0).getContext("2d");
            var phpLocChart = new Chart(ctx);

            phpLocChart.Line(self.chartData, {
                datasetFill: false,
                multiTooltipTemplate: "<%=datasetLabel%>: <%= value %>"
            });
        }
    }
});

ActiveBuild.registerPlugin(new locPlugin2());