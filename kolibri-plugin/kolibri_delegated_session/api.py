"""
One endpoint: a signed-in facility admin (or device superuser) asks for a
session that belongs to a learner of their facility.

It exists for server-side integrations that embed Kolibri content behind their
own login. Such an integration already knows who the learner is; without this
endpoint it can only reach a learner session by knowing the learner's password.
"""
import logging
import time
from importlib import import_module

from django.conf import settings
from django.contrib.auth import login
from django.http import HttpRequest
from django.middleware.csrf import get_token
from rest_framework import status
from rest_framework.response import Response
from rest_framework.views import APIView

from kolibri.core.auth.constants import role_kinds
from kolibri.core.auth.models import FacilityUser

logger = logging.getLogger(__name__)


class DelegatedSessionView(APIView):
    def post(self, request):
        caller = request.user
        if not isinstance(caller, FacilityUser):
            return _refuse(status.HTTP_403_FORBIDDEN, "NOT_AUTHENTICATED")

        try:
            target = FacilityUser.objects.get(id=request.data.get("user"))
        except (FacilityUser.DoesNotExist, ValueError, TypeError):
            return _refuse(status.HTTP_404_NOT_FOUND, "USER_NOT_FOUND")

        may_delegate = caller.is_superuser or caller.has_role_for_collection(
            [role_kinds.ADMIN], target.facility
        )
        if not may_delegate:
            return _refuse(status.HTTP_403_FORBIDDEN, "NOT_AN_ADMIN_OF_THIS_FACILITY")

        # A minted session must never carry more rights than a learner has, so an
        # admin cannot use this to become a coach, another admin or a superuser.
        if target.is_superuser or target.roles.exists():
            return _refuse(status.HTTP_403_FORBIDDEN, "TARGET_IS_NOT_A_LEARNER")

        learner_request = _fresh_request(request)
        login(learner_request, target, backend=settings.AUTHENTICATION_BACKENDS[0])
        learner_request.session["last_session_request"] = int(time.time())
        learner_request.session.save()

        logger.info(
            "Delegated session: %s (%s) opened a session for learner %s",
            caller.username,
            caller.id,
            target.id,
        )

        return Response(
            {
                "user_id": target.id,
                "facility": target.facility_id,
                "session": {
                    "cookie": settings.SESSION_COOKIE_NAME,
                    "value": learner_request.session.session_key,
                },
                "csrf": {
                    "cookie": settings.CSRF_COOKIE_NAME,
                    "token": get_token(learner_request),
                },
            },
            status=status.HTTP_201_CREATED,
        )


def _fresh_request(request):
    """
    A request with a new, empty session. Logging the learner in on the caller's
    own request would replace the admin's session with the learner's.
    """
    fresh = HttpRequest()
    fresh.META = {
        key: request.META[key]
        for key in ("REMOTE_ADDR", "SERVER_NAME", "SERVER_PORT", "HTTP_USER_AGENT")
        if key in request.META
    }
    fresh.session = import_module(settings.SESSION_ENGINE).SessionStore()
    return fresh


def _refuse(code, error_id):
    return Response([{"id": error_id, "metadata": {}}], status=code)
