#!/usr/bin/env python3
"""Focused negative checks for the R1 restore and shutdown assertions."""
import unittest

from isolated_smoke import require_exit, wait_listener


class FakeChild:
    def __init__(self, code=None):
        self.returncode = code

    def poll(self):
        return self.returncode


class RunnerAssertions(unittest.TestCase):
    def test_missing_listener_refuses_pass(self):
        with self.assertRaisesRegex(RuntimeError, 'listener.*missing'):
            wait_listener(FakeChild(), 43319, seconds=.15, probe=lambda: False)

    def test_unexpected_process_exit_refuses_pass(self):
        with self.assertRaisesRegex(RuntimeError, 'exit 1, expected 0'):
            require_exit(FakeChild(1), 0, 'restored Stratum')

    def test_exit_before_listener_refuses_pass(self):
        with self.assertRaisesRegex(RuntimeError, 'exited before opening'):
            wait_listener(FakeChild(1), 43319, seconds=.15, probe=lambda: True)


if __name__ == '__main__':
    unittest.main()
