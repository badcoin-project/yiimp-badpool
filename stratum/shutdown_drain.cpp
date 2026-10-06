#include "shutdown_drain.h"
#include <pthread.h>
#include <string.h>

volatile sig_atomic_t g_shutdown_requested = 0;

static pthread_mutex_t g_submission_gate = PTHREAD_MUTEX_INITIALIZER;
static pthread_cond_t g_submission_gate_idle = PTHREAD_COND_INITIALIZER;
static bool g_drain_started = false;
static unsigned int g_active_submissions = 0;

static void stratum_shutdown_signal(int)
{
	g_shutdown_requested = 1;
}

bool stratum_install_shutdown_handlers()
{
	struct sigaction action;
	memset(&action, 0, sizeof(action));
	action.sa_handler = stratum_shutdown_signal;
	sigemptyset(&action.sa_mask);
	// Do not request SA_RESTART: a blocking accept()/sleep() can return so the
	// normal control path observes the flag promptly.
	if(sigaction(SIGTERM, &action, NULL) != 0) return false;
	if(sigaction(SIGINT, &action, NULL) != 0) return false;
	return true;
}

bool stratum_shutdown_requested()
{
	return g_shutdown_requested != 0;
}

bool stratum_submission_begin()
{
	pthread_mutex_lock(&g_submission_gate);
	if(g_drain_started || stratum_shutdown_requested())
	{
		pthread_mutex_unlock(&g_submission_gate);
		return false;
	}
	g_active_submissions++;
	pthread_mutex_unlock(&g_submission_gate);
	return true;
}

void stratum_submission_end()
{
	pthread_mutex_lock(&g_submission_gate);
	if(g_active_submissions) g_active_submissions--;
	if(!g_active_submissions) pthread_cond_broadcast(&g_submission_gate_idle);
	pthread_mutex_unlock(&g_submission_gate);
}

void stratum_begin_drain()
{
	// Closing the gate and waiting establishes a complete boundary: all earlier
	// submissions have executed share_add(), while later ones are refused.  The
	// counter allows normal mining submissions to run concurrently.
	pthread_mutex_lock(&g_submission_gate);
	g_drain_started = true;
	while(g_active_submissions)
		pthread_cond_wait(&g_submission_gate_idle, &g_submission_gate);
	pthread_mutex_unlock(&g_submission_gate);
}
