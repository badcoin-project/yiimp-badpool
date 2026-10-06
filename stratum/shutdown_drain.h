#ifndef YAAMP_SHUTDOWN_DRAIN_H
#define YAAMP_SHUTDOWN_DRAIN_H

#include <signal.h>

// The signal handler only stores this flag.  All locking, logging and database
// work is deliberately performed by the normal control thread.
extern volatile sig_atomic_t g_shutdown_requested;

bool stratum_install_shutdown_handlers();
bool stratum_shutdown_requested();

// A submission that owns this gate is part of the pre-drain writer boundary.
// begin_drain waits for such submissions to finish adding their worker record.
bool stratum_submission_begin();
void stratum_submission_end();
void stratum_begin_drain();

class StratumSubmissionGate
{
public:
	StratumSubmissionGate(): entered_(stratum_submission_begin()) {}
	~StratumSubmissionGate() { if(entered_) stratum_submission_end(); }
	bool entered() const { return entered_; }

private:
	bool entered_;
};

#endif
