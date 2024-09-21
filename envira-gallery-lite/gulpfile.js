const gulp = require('gulp');
const plugins = require('gulp-load-plugins')();
const run = require('gulp-run-command').default;
/**
 * Helper method to load task.
 *
 * @since 1.0.0
 *
 * @param {*} task  Task to Load.
 */
function getTask(task) {
	return require('./_tasks/' + task)(gulp, plugins);
}

const scripts = getTask('scripts');
const styles = getTask('styles');
const metabox = getTask('metabox');
const gutenberg = getTask('gutenberg');

gulp.task('scripts', scripts);
gulp.task('styles', styles);
gulp.task('metabox', metabox);
gulp.task('gutenberg', gutenberg);

gulp.task('build', gulp.parallel(scripts, styles, metabox, gutenberg));

gulp.task('watch', function () {
	gulp.watch('assets/js/*.js', scripts);
	gulp.watch('assets/scss/*.scss', styles);
	gulp.watch('_gutenberg/**/*', gulp.series(gutenberg));
});

gulp.task('plugins', function () {
	return new Promise(function (resolve, reject) {
		console.log('gulp plugins');
		console.log(plugins);
		resolve();
	});
});
