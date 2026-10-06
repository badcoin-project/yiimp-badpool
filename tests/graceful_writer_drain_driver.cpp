// Process-level signal test for the production shutdown gate.  This driver
// deliberately uses shutdown_drain.cpp itself; its in-memory queue models the
// worker records owned by share_write() and records persistence exactly once.
#include "shutdown_drain.h"
#include <signal.h>
#include <sys/wait.h>
#include <unistd.h>
#include <pthread.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

static int pending = 0;
static int persisted = 0;
static bool fail_write = false;
static volatile sig_atomic_t race_entered = 0;

static bool persist_pending()
{
	if(fail_write) return false;
	persisted += pending;
	pending = 0;
	return true;
}

static void *racing_submit(void *)
{
	StratumSubmissionGate gate;
	if(gate.entered())
	{
		race_entered = 1;
		usleep(150000);
		pending++;
	}
	return NULL;
}

static int child(const char *mode, int ready_fd)
{
	if(!stratum_install_shutdown_handlers()) return 90;
	if(!strcmp(mode, "none")) pending = 0;
	else if(!strcmp(mode, "one")) pending = 1;
	else if(!strcmp(mode, "many")) pending = 25;
	else if(!strcmp(mode, "block")) pending = 1;
	else if(!strcmp(mode, "legacy")) pending = 3;
	else if(!strcmp(mode, "durable")) pending = 3;
	else if(!strcmp(mode, "fail") || !strcmp(mode, "unavailable")) { pending = 3; fail_write = true; }

	pthread_t race;
	bool has_race = !strcmp(mode, "race");
	if(has_race) pthread_create(&race, NULL, racing_submit, NULL);
	while(has_race && !race_entered) usleep(1000);

	char ready = 'R';
	write(ready_fd, &ready, 1);
	while(!stratum_shutdown_requested()) usleep(10000);

	stratum_begin_drain();
	if(has_race) pthread_join(race, NULL);
	bool no_new_submission = !stratum_submission_begin();
	bool wrote = persist_pending();
	printf("mode=%s wrote=%d pending=%d persisted=%d refused_after_boundary=%d\n",
		mode, wrote?1:0, pending, persisted, no_new_submission?1:0);
	fflush(stdout);
	if(!wrote || pending) return 2;
	return no_new_submission?0:3;
}

static int run_case(const char *mode, int signal_number)
{
	int ready[2];
	if(pipe(ready)) return 70;
	pid_t pid = fork();
	if(pid < 0) return 71;
	if(pid == 0)
	{
		close(ready[0]);
		int result = child(mode, ready[1]);
		close(ready[1]);
		_exit(result);
	}
	close(ready[1]);
	char value = 0;
	if(read(ready[0], &value, 1) != 1) return 72;
	close(ready[0]);
	if(kill(pid, signal_number)) return 73;
	if(!strcmp(mode, "many")) kill(pid, SIGTERM); // repeated SIGTERM is idempotent.
	int status = 0;
	if(waitpid(pid, &status, 0) < 0) return 74;
	if(!strcmp(mode, "fail") || !strcmp(mode, "unavailable"))
		return WIFEXITED(status) && WEXITSTATUS(status) == 2 ? 0 : 75;
	return WIFEXITED(status) && WEXITSTATUS(status) == 0 ? 0 : 76;
}

int main()
{
	const char *term_cases[] = {"none", "one", "many", "race", "block", "legacy", "durable", "fail", "unavailable"};
	for(unsigned int i=0; i<sizeof(term_cases)/sizeof(term_cases[0]); i++)
	{
		int result = run_case(term_cases[i], SIGTERM);
		if(result) { fprintf(stderr, "FAIL %s %d\n", term_cases[i], result); return result; }
	}
	int result = run_case("one", SIGINT);
	if(result) { fprintf(stderr, "FAIL sigint %d\n", result); return result; }
	puts("GRACEFUL_WRITER_DRAIN_PROCESS_TEST=PASS");
	return 0;
}
